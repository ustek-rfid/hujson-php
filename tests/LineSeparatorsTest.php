<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\HuJSONException;

/** Ported from json_test.go (TestLineSeparators). */
final class LineSeparatorsTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string}> */
    public static function cases(): array
    {
        return [
            'U+2028 in string' => ["\"a\u{2028}b\"", null],
            'U+2029 in string' => ["\"a\u{2029}b\"", null],
            'U+2028 in block comment' => ["/* a\u{2028}b */ null", null],
            'U+2029 in block comment' => ["/* a\u{2029}b */ null", null],
            'U+2028 as whitespace' => [
                "null\u{2028}",
                "hujson: line 1, column 5: invalid character '\\u2028' after top-level value",
            ],
            'U+2029 as whitespace' => [
                "null\u{2029}",
                "hujson: line 1, column 5: invalid character '\\u2029' after top-level value",
            ],
            'U+2028 in line comment' => [
                "// hidden\u{2028}null\ntrue",
                "hujson: line 1, column 10: invalid character '\\u2028' in line comment",
            ],
            'U+2029 in line comment' => [
                "// hidden\u{2029}null\ntrue",
                "hujson: line 1, column 10: invalid character '\\u2029' in line comment",
            ],
            'U+2028 in line comment at EOF' => [
                "// hidden\u{2028}null",
                "hujson: line 1, column 10: invalid character '\\u2028' in line comment",
            ],
            'U+2029 in line comment at EOF' => [
                "// hidden\u{2029}null",
                "hujson: line 1, column 10: invalid character '\\u2029' in line comment",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testLineSeparators(string $in, ?string $wantErr): void
    {
        $err = null;
        try {
            HuJSON::parse($in);
        } catch (HuJSONException $e) {
            $err = $e->getMessage();
        }
        self::assertSame($wantErr, $err);
    }
}
