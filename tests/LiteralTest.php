<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\Kind;
use Ustek\HuJSON\Literal;

/** Exercises the Literal constructors and accessors (types.go). */
final class LiteralTest extends TestCase
{
    public function testConstructors(): void
    {
        self::assertSame('true', Literal::fromBool(true)->bytes);
        self::assertSame('false', Literal::fromBool(false)->bytes);
        self::assertSame('-42', Literal::fromInt(-42)->bytes);
        self::assertSame('1000', Literal::fromUint(1000)->bytes);
        self::assertSame('3.14', Literal::fromFloat(3.14)->bytes);
        self::assertSame('"NaN"', Literal::fromFloat(NAN)->bytes);
        self::assertSame('"Infinity"', Literal::fromFloat(INF)->bytes);
        self::assertSame('"-Infinity"', Literal::fromFloat(-INF)->bytes);
    }

    public function testFromStringEscaping(): void
    {
        self::assertSame('"hi"', Literal::fromString('hi')->bytes);
        self::assertSame('"a\\"b\\\\c"', Literal::fromString("a\"b\\c")->bytes);
        // Mirrors the format_test.go normalization row.
        self::assertSame('"\u000f\n/😂"', Literal::fromString("\x0F\n/\u{1F602}")->bytes);
        // Invalid UTF-8 becomes the replacement character.
        self::assertSame("\"\u{FFFD}\"", Literal::fromString("\xff")->bytes);
    }

    public function testKind(): void
    {
        self::assertSame(Kind::NULL, (new Literal('null'))->kind());
        self::assertSame(Kind::TRUE_, (new Literal('true'))->kind());
        self::assertSame(Kind::FALSE_, (new Literal('false'))->kind());
        self::assertSame(Kind::STRING, (new Literal('"x"'))->kind());
        self::assertSame(Kind::NUMBER, (new Literal('123'))->kind());
        self::assertSame(Kind::NUMBER, (new Literal('-1'))->kind());
        self::assertSame(Kind::INVALID, (new Literal(''))->kind());
    }

    public function testIsValid(): void
    {
        self::assertTrue((new Literal('null'))->isValid());
        self::assertTrue((new Literal('3.14159E+435'))->isValid());
        self::assertTrue((new Literal('"\"\\\\\u0022😊"'))->isValid());
        self::assertFalse((new Literal('nul'))->isValid());
        self::assertFalse((new Literal('+1000'))->isValid());
        self::assertFalse((new Literal('"\xff"'))->isValid());
        self::assertFalse((new Literal(''))->isValid());
        self::assertFalse((new Literal('1.2.3'))->isValid());
    }

    public function testAccessors(): void
    {
        self::assertTrue((new Literal('true'))->asBool());
        self::assertFalse((new Literal('1'))->asBool());

        self::assertSame('hi', (new Literal('"hi"'))->asString());
        self::assertSame("a\nb", (new Literal('"a\nb"'))->asString());
        self::assertSame('123', (new Literal('123'))->asString());

        self::assertSame(42, (new Literal('42'))->asInt());
        self::assertSame(0, (new Literal('3.14'))->asInt());
        self::assertSame(-5, (new Literal('-5'))->asInt());
        self::assertSame(0, (new Literal('"x"'))->asInt());

        self::assertSame(42, (new Literal('42'))->asUint());
        self::assertSame(0, (new Literal('-5'))->asUint());

        self::assertSame(3.14, (new Literal('3.14'))->asFloat());
        self::assertSame(5.0, (new Literal('5'))->asFloat());
        self::assertTrue(is_nan((new Literal('"NaN"'))->asFloat()));
        self::assertSame(INF, (new Literal('"Infinity"'))->asFloat());
        self::assertSame(-INF, (new Literal('"-Infinity"'))->asFloat());
    }

    public function testEqualString(): void
    {
        self::assertTrue((new Literal('"fizz"'))->equalString('fizz'));
        self::assertTrue((new Literal('"\u0066izz"'))->equalString('fizz'));
        self::assertFalse((new Literal('"foo"'))->equalString('bar'));
        self::assertFalse((new Literal('123'))->equalString('123'));
    }
}
