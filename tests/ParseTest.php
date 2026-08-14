<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\HuJSONException;
use Ustek\HuJSON\Literal;
use Ustek\HuJSON\ObjectValue;

/**
 * Ported from json_test.go (Test). Each row is [input, expectedError, wantMin,
 * wantStd]; a null error means the input parses. For parsing rows we assert the
 * exact error message; for successful rows we assert a byte-for-byte pack
 * round-trip, IsStandard, and the Minimize/Standardize buffers.
 */
final class ParseTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string,2:?string,3:?string}> */
    public static function rows(): array
    {
        return [
            'null spaces' => [' null ', null, 'null', ' null '],
            'null trailing comma' => [
                ' null,',
                'hujson: line 1, column 6: invalid character \',\' after top-level value',
                null,
                null,
            ],
            'comments around null' => [
                "//😊 \r\t\n/*\r\t\n*/null//😊 \r\t\n/*\r\t\n*/",
                null,
                'null',
                "       \r\t\n  \r\t\n  null       \r\t\n  \r\t\n  ",
            ],
            'slash question' => ['/?', 'hujson: line 1, column 1: invalid character \'/\' at start of value', null, null],
            'invalid utf8 comment' => [
                "//\xde\xad\xbe\xef\nnull",
                'hujson: line 1, column 1: invalid UTF-8 in comment',
                null,
                null,
            ],
            'line comment eof' => ['null//', 'hujson: line 1, column 5: parsing comment: unexpected EOF', null, null],
            'line comment newline' => ["null//\n", null, 'null', "null  \n"],
            'string eof' => [
                '"\\"\\\\\\u0022😊',
                'hujson: line 1, column 16: parsing string: unexpected EOF',
                null,
                null,
            ],
            'invalid escape literal' => [
                '"\\xff"',
                'hujson: line 1, column 1: invalid literal: "\\xff"',
                null,
                null,
            ],
            'string with escapes' => ['"\\"\\\\\\u0022😊"', null, '"\\"\\\\\\u0022😊"', '"\\"\\\\\\u0022😊"'],
            'big exponent' => ['3.14159E+435', null, '3.14159E+435', '3.14159E+435'],
            'leading plus' => ['+1000', 'hujson: line 1, column 1: invalid literal: +1000', null, null],
            'open brace eof' => ['{', 'hujson: line 1, column 2: parsing value: unexpected EOF', null, null],
            'brace comma' => ['{,}', 'hujson: line 1, column 2: invalid character \',\' at start of value', null, null],
            'non-string name' => [
                '{null:"v"',
                'hujson: line 1, column 2: invalid character \'n\' at start of object name',
                null,
                null,
            ],
            'name eof' => ['{"k"', 'hujson: line 1, column 5: parsing object after name: unexpected EOF', null, null],
            'name semicolon' => [
                '{"k";',
                'hujson: line 1, column 5: invalid character \';\' after object name',
                null,
                null,
            ],
            'empty value' => ['{"k":}', 'hujson: line 1, column 6: invalid character \'}\' at start of value', null, null],
            'object value eof' => [
                '{"k":"v"',
                'hujson: line 1, column 9: parsing object after value: unexpected EOF',
                null,
                null,
            ],
            'object bracket' => [
                '{"k":"v"]',
                'hujson: line 1, column 9: invalid character \']\' after object value (expecting \',\' or \'}\')',
                null,
                null,
            ],
            'spaced object' => [' { "k" : "v" } ', null, '{"k":"v"}', ' { "k" : "v" } '],
            'spaced object trailing comma' => [' { "k" : "v" , } ', null, '{"k":"v"}', ' { "k" : "v"   } '],
            'open bracket eof' => ['[', 'hujson: line 1, column 2: parsing value: unexpected EOF', null, null],
            'bracket comma' => ['[,]', 'hujson: line 1, column 2: invalid character \',\' at start of value', null, null],
            'array value eof' => ['["s"', 'hujson: line 1, column 5: parsing array after value: unexpected EOF', null, null],
            'array brace' => [
                '["s"}',
                'hujson: line 1, column 5: invalid character \'}\' after array value (expecting \',\' or \']\')',
                null,
                null,
            ],
            'spaced array' => [' [ "s" ] ', null, '["s"]', ' [ "s" ] '],
            'spaced array trailing comma' => [' [ "s" , ] ', null, '["s"]', ' [ "s"   ] '],
            'everything with comments' => [
                ' /**/ [ /**/ null /**/ , /**/ false /**/ , /**/ true /**/ , /**/ "string" /**/ , /**/ 0 /**/ , /**/ {} /**/ , /**/ [] /**/ ] /**/ ',
                null,
                '[null,false,true,"string",0,{},[]]',
                '      [      null      ,      false      ,      true      ,      "string"      ,      0      ,      {}      ,      []      ]      ',
            ],
            'invalid byte' => [
                " \xff",
                'hujson: line 1, column 2: invalid character \'\\xff\' at start of value',
                null,
                null,
            ],
            'apostrophe' => [
                " '",
                'hujson: line 1, column 2: invalid character \'\\\'\' at start of value',
                null,
                null,
            ],
            'emoji' => [
                ' 💩',
                'hujson: line 1, column 2: invalid character \'💩\' at start of value',
                null,
                null,
            ],
            'uffff' => [
                " \u{FFFF}",
                'hujson: line 1, column 2: invalid character \'\\uffff\' at start of value',
                null,
                null,
            ],
            'supplementary' => [
                " \u{101234}",
                'hujson: line 1, column 2: invalid character \'\\U00101234\' at start of value',
                null,
                null,
            ],
        ];
    }

    #[DataProvider('rows')]
    public function testParse(string $in, ?string $expErr, ?string $wantMin, ?string $wantStd): void
    {
        $err = null;
        $v = null;
        try {
            $v = HuJSON::parse($in);
        } catch (HuJSONException $e) {
            $err = $e->getMessage();
        }

        if ($expErr !== null) {
            self::assertSame($expErr, $err, 'error message');
            return;
        }

        self::assertNull($err, 'unexpected parse error');
        self::assertNotNull($v);
        self::assertSame($in, $v->pack(), 'pack round-trip');
        self::assertSame($in === $wantStd, $v->isStandard(), 'isStandard');

        if ($wantMin !== null) {
            $c = clone $v;
            $c->minimize();
            self::assertSame($wantMin, $c->pack(), 'minimize buffer');
            self::assertTrue($c->isStandard(), 'minimized is standard');
        }
        if ($wantStd !== null) {
            $c = clone $v;
            $c->standardize();
            self::assertSame($wantStd, $c->pack(), 'standardize buffer');
            self::assertTrue($c->isStandard(), 'standardized is standard');
        }
    }

    public function testOffsetsScalar(): void
    {
        $v = HuJSON::parse(' null ');
        self::assertSame(' ', $v->beforeExtra);
        self::assertSame(1, $v->startOffset);
        self::assertSame(5, $v->endOffset);
        self::assertSame(' ', $v->afterExtra);
        self::assertInstanceOf(Literal::class, $v->value);
        self::assertSame('null', $v->value->bytes);
    }

    public function testOffsetsObject(): void
    {
        $v = HuJSON::parse(' { "k" : "v" } ');
        self::assertSame(1, $v->startOffset);
        self::assertSame(14, $v->endOffset);
        self::assertInstanceOf(ObjectValue::class, $v->value);
        $m = $v->value->members[0];
        self::assertSame(3, $m->name->startOffset);
        self::assertSame(6, $m->name->endOffset);
        self::assertSame(9, $m->value->startOffset);
        self::assertSame(12, $m->value->endOffset);
    }
}
