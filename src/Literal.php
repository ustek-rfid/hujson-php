<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Literal is the raw bytes for a JSON null, boolean, string, or number.
 * It contains no surrounding whitespace or comments and is immutable.
 *
 * Go API mapping: the package-level constructors hujson.Bool/String/Int/Uint/Float
 * become Literal::fromBool/fromString/fromInt/fromUint/fromFloat; the methods
 * Literal.Bool/String/Int/Uint/Float/IsValid/Kind become
 * asBool/asString/asInt/asUint/asFloat/isValid/kind.
 */
final class Literal implements ValueTrimmed
{
    public function __construct(public readonly string $bytes)
    {
    }

    /** fromBool constructs a JSON literal for a boolean. */
    public static function fromBool(bool $v): self
    {
        return new self($v ? 'true' : 'false');
    }

    /**
     * fromString constructs a JSON literal for a string, formatting according to
     * RFC 8785, section 3.2.2.2. Invalid UTF-8 is mangled with U+FFFD.
     */
    public static function fromString(string $v): self
    {
        $b = '"';
        $len = strlen($v);
        $i = 0;
        while ($i < $len) {
            [$r, $size] = Chars::decodeRune($v, $i);
            if ($r < 0x20 || $r === 0x5C || $r === 0x22) {
                $b .= match ($r) {
                    0x08 => '\\b',
                    0x09 => '\\t',
                    0x0A => '\\n',
                    0x0C => '\\f',
                    0x0D => '\\r',
                    0x5C => '\\\\',
                    0x22 => '\\"',
                    default => sprintf('\\u%04x', $r),
                };
            } else {
                $b .= Chars::encodeRune($r);
            }
            $i += $size > 0 ? $size : 1;
        }
        return new self($b . '"');
    }

    /**
     * fromInt constructs a JSON literal for a signed integer.
     * PHP integers are 64-bit signed; values outside that range are not
     * representable (see README/notes).
     */
    public static function fromInt(int $v): self
    {
        return new self((string) $v);
    }

    /** fromUint constructs a JSON literal for a non-negative integer. */
    public static function fromUint(int $v): self
    {
        return new self((string) $v);
    }

    /**
     * fromFloat constructs a JSON literal for a floating-point number.
     * NaN, +Inf, and -Inf are represented as the JSON strings
     * "NaN", "Infinity", and "-Infinity".
     */
    public static function fromFloat(float $v): self
    {
        if (is_nan($v)) {
            return new self('"NaN"');
        }
        if (is_infinite($v)) {
            return new self($v > 0 ? '"Infinity"' : '"-Infinity"');
        }
        return new self((string) json_encode($v));
    }

    public function kind(): string
    {
        if ($this->bytes === '') {
            return Kind::INVALID;
        }
        $c = $this->bytes[0];
        switch ($c) {
            case 'n':
                return Kind::NULL;
            case 'f':
                return Kind::FALSE_;
            case 't':
                return Kind::TRUE_;
            case '"':
                return Kind::STRING;
        }
        if ($c === '-' || ($c >= '0' && $c <= '9')) {
            return Kind::NUMBER;
        }
        return Kind::INVALID;
    }

    /**
     * isValid reports whether the bytes are a valid JSON null, boolean, string,
     * or number with no surrounding whitespace.
     */
    public function isValid(): bool
    {
        if ($this->bytes === '' || $this->bytes !== Chars::trimSpace($this->bytes)) {
            return false;
        }
        // The v1 json package (and Go's json.Valid) does not enforce valid UTF-8
        // inside strings; JSON_INVALID_UTF8_IGNORE mirrors that leniency.
        json_decode($this->bytes, true, 512, JSON_INVALID_UTF8_IGNORE);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /** asBool returns the value for a JSON boolean (false otherwise). */
    public function asBool(): bool
    {
        return $this->bytes === 'true';
    }

    /**
     * asString returns the unescaped string for a JSON string.
     * For other kinds it returns the raw JSON representation.
     */
    public function asString(): string
    {
        if ($this->kind() === Kind::STRING) {
            $s = json_decode($this->bytes, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() === JSON_ERROR_NONE && is_string($s)) {
                return $s;
            }
        }
        return $this->bytes;
    }

    /** asInt returns the signed integer for a JSON number (0 otherwise). */
    public function asInt(): int
    {
        if ($this->kind() === Kind::NUMBER) {
            $n = filter_var($this->bytes, FILTER_VALIDATE_INT);
            if ($n !== false) {
                return $n;
            }
        }
        return 0;
    }

    /** asUint returns the non-negative integer for a JSON number (0 otherwise). */
    public function asUint(): int
    {
        if ($this->kind() === Kind::NUMBER) {
            $n = filter_var($this->bytes, FILTER_VALIDATE_INT);
            if ($n !== false && $n >= 0) {
                return $n;
            }
        }
        return 0;
    }

    /**
     * asFloat returns the floating-point value for a JSON number, or NaN/±Inf for
     * the JSON strings "NaN"/"Infinity"/"-Infinity". It returns 0.0 otherwise.
     */
    public function asFloat(): float
    {
        if ($this->kind() === Kind::NUMBER) {
            $n = json_decode($this->bytes);
            return is_int($n) || is_float($n) ? (float) $n : 0.0;
        }
        if ($this->kind() === Kind::STRING) {
            switch ($this->asString()) {
                case 'NaN':
                    return NAN;
                case 'Infinity':
                    return INF;
                case '-Infinity':
                    return -INF;
            }
        }
        return 0.0;
    }

    /** equalString reports whether this JSON string equals $s. */
    public function equalString(string $s): bool
    {
        $b = $this->bytes;
        $len = strlen($b);
        // Fast-path: assume there are no escape characters.
        if ($len >= 2 && $b[0] === '"' && $b[$len - 1] === '"' && !str_contains($b, '\\')) {
            return substr($b, 1, -1) === $s;
        }
        // Slow-path: unescape and compare.
        if ($this->kind() === Kind::STRING) {
            $s2 = json_decode($b, true);
            return json_last_error() === JSON_ERROR_NONE && $s2 === $s;
        }
        return false;
    }

    public function cloneTrimmed(): ValueTrimmed
    {
        return $this; // immutable
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
