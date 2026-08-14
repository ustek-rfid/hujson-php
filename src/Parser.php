<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Parser parses HuJSON input into a {@see Value} syntax tree (a port of parse.go).
 * Extra and Literal values in the result reference substrings of the input.
 *
 * @internal
 */
final class Parser
{
    /** Mirrors Go's io.ErrUnexpectedEOF.Error(). */
    public const UNEXPECTED_EOF = 'unexpected EOF';

    public function __construct(private string $b)
    {
    }

    /**
     * run parses the input as a single top-level value and rejects trailing
     * bytes. Any {@see ParseException} is translated into a line/column
     * {@see HuJSONException}.
     */
    public function run(): Value
    {
        try {
            [$v, $n] = $this->parseNext(0);
            if ($n < strlen($this->b)) {
                throw Chars::invalidCharacter($this->b, $n, 'after top-level value');
            }
            return $v;
        } catch (ParseException $e) {
            [$line, $col] = Chars::lineColumn($this->b, $e->offset);
            throw new HuJSONException(
                sprintf('hujson: line %d, column %d: %s', $line, $col, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * parseNext parses the next value with surrounding whitespace and comments.
     *
     * @return array{0:Value,1:int}
     */
    private function parseNext(int $n): array
    {
        $v = new Value();
        $n0 = $n;

        // Consume leading whitespace and comments.
        $n = self::consumeExtra($this->b, $n);
        if ($n > $n0) {
            $v->beforeExtra = substr($this->b, $n0, $n - $n0);
        }

        // Parse the next value.
        $v->startOffset = $n;
        try {
            [$v->value, $n] = $this->parseNextTrimmed($n);
        } catch (ParseSentinel $s) {
            $s->partial = $v;
            throw $s;
        }
        $v->endOffset = $n;

        // Consume trailing whitespace and comments.
        $n = self::consumeExtra($this->b, $n);
        if ($n > $v->endOffset) {
            $v->afterExtra = substr($this->b, $v->endOffset, $n - $v->endOffset);
        }

        return [$v, $n];
    }

    /**
     * parseNextTrimmed parses the next value without surrounding whitespace and
     * comments. It throws a {@see ParseSentinel} on a '}' or ']' at value-start.
     *
     * @return array{0:?ValueTrimmed,1:int}
     */
    private function parseNextTrimmed(int $n): array
    {
        $b = $this->b;
        $len = strlen($b);
        if ($len === $n) {
            throw new ParseException('parsing value: ' . self::UNEXPECTED_EOF, $n);
        }

        switch ($b[$n]) {
            case '{':
                $n++;
                $obj = new ObjectValue();
                while (true) {
                    // Parse the name.
                    try {
                        [$vk, $n] = $this->parseNext($n);
                    } catch (ParseSentinel $s) {
                        if ($s->kind === '}' && $s->partial !== null && $s->partial->value === null) {
                            Nodes::setTrailingComma($obj, $obj->length() > 0);
                            $obj->afterExtra = $s->partial->beforeExtra;
                            return [$obj, $s->offset + 1];
                        }
                        throw $s;
                    }
                    if ($vk->value === null || $vk->value->kind() !== Kind::STRING) {
                        throw Chars::invalidCharacter($b, $vk->startOffset, 'at start of object name');
                    }

                    // Parse the colon.
                    if ($len === $n) {
                        throw new ParseException('parsing object after name: ' . self::UNEXPECTED_EOF, $n);
                    }
                    if ($b[$n] !== ':') {
                        throw Chars::invalidCharacter($b, $n, 'after object name');
                    }
                    $n++;

                    // Parse the value (a '}'/']' here must propagate as an error).
                    [$vv, $n] = $this->parseNext($n);

                    $obj->members[] = new ObjectMember($vk, $vv);
                    if ($len === $n) {
                        throw new ParseException('parsing object after value: ' . self::UNEXPECTED_EOF, $n);
                    }
                    if ($b[$n] === ',') {
                        $n++;
                    } elseif ($b[$n] === '}') {
                        // Move AfterExtra from last value to AfterExtra of the object.
                        $last = $obj->members[count($obj->members) - 1]->value;
                        $obj->afterExtra = $last->afterExtra;
                        $last->afterExtra = null;
                        return [$obj, $n + 1];
                    } else {
                        throw Chars::invalidCharacter($b, $n, "after object value (expecting ',' or '}')");
                    }
                }
                // no break (unreachable)
            case '}':
                throw new ParseSentinel("invalid character '}' at start of value", $n, '}');

            case '[':
                $n++;
                $arr = new ArrayValue();
                while (true) {
                    try {
                        [$el, $n] = $this->parseNext($n);
                    } catch (ParseSentinel $s) {
                        if ($s->kind === ']' && $s->partial !== null && $s->partial->value === null) {
                            Nodes::setTrailingComma($arr, $arr->length() > 0);
                            $arr->afterExtra = $s->partial->beforeExtra;
                            return [$arr, $s->offset + 1];
                        }
                        throw $s;
                    }
                    $arr->elements[] = $el;
                    if ($len === $n) {
                        throw new ParseException('parsing array after value: ' . self::UNEXPECTED_EOF, $n);
                    }
                    if ($b[$n] === ',') {
                        $n++;
                    } elseif ($b[$n] === ']') {
                        $last = $arr->elements[count($arr->elements) - 1];
                        $arr->afterExtra = $last->afterExtra;
                        $last->afterExtra = null;
                        return [$arr, $n + 1];
                    } else {
                        throw Chars::invalidCharacter($b, $n, "after array value (expecting ',' or ']')");
                    }
                }
                // no break (unreachable)
            case ']':
                throw new ParseSentinel("invalid character ']' at start of value", $n, ']');

            case '"':
                $n0 = $n;
                $n++;
                $inEscape = false;
                while (true) {
                    if ($len === $n) {
                        throw new ParseException('parsing string: ' . self::UNEXPECTED_EOF, $n);
                    }
                    if ($inEscape) {
                        $inEscape = false;
                    } elseif ($b[$n] === '\\') {
                        $inEscape = true;
                    } elseif ($b[$n] === '"') {
                        $n++;
                        $lit = new Literal(substr($b, $n0, $n - $n0));
                        if (!$lit->isValid()) {
                            throw new ParseException('invalid literal: ' . $lit->bytes, $n0);
                        }
                        return [$lit, $n];
                    }
                    $n++;
                }
                // no break (unreachable)
            default:
                $n0 = $n;
                while ($len > $n) {
                    $ch = $b[$n];
                    if (
                        $ch === '-' || $ch === '+' || $ch === '.'
                        || ($ch >= 'a' && $ch <= 'z')
                        || ($ch >= 'A' && $ch <= 'Z')
                        || ($ch >= '0' && $ch <= '9')
                    ) {
                        $n++;
                    } else {
                        break;
                    }
                }
                $lit = new Literal(substr($b, $n0, $n - $n0));
                if ($lit->bytes === '') {
                    throw Chars::invalidCharacter($b, $n0, 'at start of value');
                }
                if (!$lit->isValid()) {
                    throw new ParseException('invalid literal: ' . $lit->bytes, $n0);
                }
                return [$lit, $n];
        }
    }

    /**
     * consumeExtra consumes leading whitespace and comments starting at $n and
     * returns the new offset. It throws {@see ParseException} on an invalid or
     * unterminated comment. Static so {@see Extra::isValid()} can reuse it.
     */
    public static function consumeExtra(string $b, int $n): int
    {
        $len = strlen($b);
        while ($len > $n) {
            $c = $b[$n];
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $n += Extra::consumeWhitespace($b, $n);
            } elseif ($c === '/') {
                $isLineComment = substr($b, $n, 2) === '//';
                $nc = Extra::consumeComment($b, $n);
                if ($nc === 0) {
                    return $n;
                }
                if ($nc < 0) {
                    if ($isLineComment) {
                        $i = Extra::indexLineSeparator(substr($b, $n));
                        if ($i >= 0) {
                            throw Chars::invalidCharacter($b, $n + $i, 'in line comment');
                        }
                    }
                    throw new ParseException('parsing comment: ' . self::UNEXPECTED_EOF, $n);
                }
                if (!Chars::isValidUtf8(substr($b, $n, $nc))) {
                    throw new ParseException('invalid UTF-8 in comment', $n);
                }
                if ($isLineComment) {
                    $i = Extra::indexLineSeparator(substr($b, $n, $nc));
                    if ($i >= 0) {
                        throw Chars::invalidCharacter($b, $n + $i, 'in line comment');
                    }
                }
                $n += $nc;
            } else {
                return $n;
            }
        }
        return $n;
    }
}
