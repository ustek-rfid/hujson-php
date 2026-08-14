<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\ArrayValue;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\ObjectValue;

/** Ported from find_test.go (TestFind); test document from RFC 6901, section 5. */
final class FindTest extends TestCase
{
    public function testFind(): void
    {
        $doc = '{"foo": ["bar", "baz"], "": 0, "a/b": 1, "c%d": 2, "e^f": 3, '
            . '"g|h": 4, "i\\\\j": 5, "k\\"l": 6, " ": 7, "m~n": 8}';
        $v = HuJSON::parse($doc);
        $v->minimize();

        $obj = $v->value;
        self::assertInstanceOf(ObjectValue::class, $obj);
        $foo = $obj->members[0]->value;
        self::assertInstanceOf(ArrayValue::class, $foo->value);

        // Found cases: assert node identity.
        self::assertSame($v, $v->find(''));
        self::assertSame($foo, $v->find('/foo'));
        self::assertSame($foo->value->elements[0], $v->find('/foo/0'));
        self::assertSame($obj->members[1]->value, $v->find('/'));
        self::assertSame($obj->members[2]->value, $v->find('/a~1b'));
        self::assertSame($obj->members[3]->value, $v->find('/c%d'));
        self::assertSame($obj->members[4]->value, $v->find('/e^f'));
        self::assertSame($obj->members[5]->value, $v->find('/g|h'));
        self::assertSame($obj->members[6]->value, $v->find('/i\\j'));
        self::assertSame($obj->members[7]->value, $v->find('/k"l'));
        self::assertSame($obj->members[8]->value, $v->find('/ '));
        self::assertSame($obj->members[9]->value, $v->find('/m~0n'));

        // Not-found / invalid cases.
        self::assertNull($v->find('foo'));
        self::assertNull($v->find('/foo '));
        self::assertNull($v->find('/foo/00'));
        self::assertNull($v->find('/////'));
    }
}
