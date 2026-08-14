<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Chars holds low-level byte/rune utilities that mirror the Go standard library
 * primitives the reference implementation relies on (unicode/utf8, unicode.IsPrint,
 * unicode.IsSpace) without requiring the mbstring or intl extensions.
 *
 * @internal
 */
final class Chars
{
    /** Unicode replacement character U+FFFD, encoded as UTF-8. */
    public const REPLACEMENT = "\xEF\xBF\xBD";

    /**
     * The set of runes for which Go's unicode.IsSpace returns true, as a PCRE
     * character-class body (used with the /u modifier).
     */
    private const SPACE_CLASS = '\t\n\x0B\f\r \x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /** ASCII fallback whitespace list for invalid-UTF-8 inputs. */
    private const ASCII_SPACE = " \t\n\r\x0B\x0C";

    /**
     * decodeRune decodes the first UTF-8 rune in $b at byte offset $off.
     * It is a faithful port of Go's utf8.DecodeRune: it returns
     * [codepoint, size]; an empty slice yields [0xFFFD, 0] and any invalid,
     * overlong, surrogate, or truncated sequence yields [0xFFFD, 1].
     *
     * @return array{0:int,1:int}
     */
    public static function decodeRune(string $b, int $off = 0): array
    {
        $n = strlen($b) - $off;
        if ($n < 1) {
            return [0xFFFD, 0];
        }
        $p0 = ord($b[$off]);
        if ($p0 < 0x80) {
            return [$p0, 1];
        }
        // Invalid leading bytes: continuation bytes, overlong 2-byte leaders,
        // and out-of-range 4-byte+ leaders.
        if ($p0 < 0xC2 || $p0 >= 0xF5) {
            return [0xFFFD, 1];
        }

        if ($p0 < 0xE0) {
            $sz = 2;
            $lo = 0x80;
            $hi = 0xBF;
        } elseif ($p0 < 0xF0) {
            $sz = 3;
            $lo = $p0 === 0xE0 ? 0xA0 : 0x80;
            $hi = $p0 === 0xED ? 0x9F : 0xBF;
        } else {
            $sz = 4;
            $lo = $p0 === 0xF0 ? 0x90 : 0x80;
            $hi = $p0 === 0xF4 ? 0x8F : 0xBF;
        }

        if ($n < $sz) {
            return [0xFFFD, 1];
        }
        $b1 = ord($b[$off + 1]);
        if ($b1 < $lo || $b1 > $hi) {
            return [0xFFFD, 1];
        }
        if ($sz === 2) {
            return [(($p0 & 0x1F) << 6) | ($b1 & 0x3F), 2];
        }
        $b2 = ord($b[$off + 2]);
        if ($b2 < 0x80 || $b2 > 0xBF) {
            return [0xFFFD, 1];
        }
        if ($sz === 3) {
            return [(($p0 & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F), 3];
        }
        $b3 = ord($b[$off + 3]);
        if ($b3 < 0x80 || $b3 > 0xBF) {
            return [0xFFFD, 1];
        }
        return [(($p0 & 0x07) << 18) | (($b1 & 0x3F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F), 4];
    }

    /**
     * encodeRune encodes a rune as UTF-8, mirroring Go's utf8.EncodeRune.
     * Out-of-range or surrogate code points are encoded as U+FFFD.
     */
    public static function encodeRune(int $r): string
    {
        if ($r < 0 || $r > 0x10FFFF || ($r >= 0xD800 && $r <= 0xDFFF)) {
            return self::REPLACEMENT;
        }
        if ($r < 0x80) {
            return chr($r);
        }
        if ($r < 0x800) {
            return chr(0xC0 | ($r >> 6)) . chr(0x80 | ($r & 0x3F));
        }
        if ($r < 0x10000) {
            return chr(0xE0 | ($r >> 12)) . chr(0x80 | (($r >> 6) & 0x3F)) . chr(0x80 | ($r & 0x3F));
        }
        return chr(0xF0 | ($r >> 18)) . chr(0x80 | (($r >> 12) & 0x3F)) . chr(0x80 | (($r >> 6) & 0x3F)) . chr(0x80 | ($r & 0x3F));
    }

    /** isValidUtf8 reports whether $s is entirely valid UTF-8 (Go's utf8.Valid). */
    public static function isValidUtf8(string $s): bool
    {
        return @preg_match('//u', $s) === 1;
    }

    /** isPrint reports whether a rune is printable, mirroring Go's unicode.IsPrint. */
    public static function isPrint(int $r): bool
    {
        if ($r === 0x20) {
            return true; // ASCII space is the only printable space
        }
        return !preg_match('/[\p{C}\p{Z}]/u', self::encodeRune($r));
    }

    /** rtrimSpace trims trailing Unicode whitespace (Go's TrimRightFunc(unicode.IsSpace)). */
    public static function rtrimSpace(string $s): string
    {
        $r = preg_replace('/[' . self::SPACE_CLASS . ']+$/u', '', $s);
        return $r === null ? rtrim($s, self::ASCII_SPACE) : $r;
    }

    /** trimSpace trims leading and trailing Unicode whitespace (Go's bytes.TrimSpace). */
    public static function trimSpace(string $s): string
    {
        $r = preg_replace('/^[' . self::SPACE_CLASS . ']+|[' . self::SPACE_CLASS . ']+$/u', '', $s);
        return $r === null ? trim($s, self::ASCII_SPACE) : $r;
    }

    /**
     * lineColumn returns the 1-indexed line and column of byte offset $n in $b.
     *
     * @return array{0:int,1:int}
     */
    public static function lineColumn(string $b, int $n): array
    {
        $prefix = substr($b, 0, $n);
        $line = 1 + substr_count($prefix, "\n");
        $lastNl = strrpos($prefix, "\n");
        $column = 1 + $n - (($lastNl === false ? -1 : $lastNl) + 1);
        return [$line, $column];
    }

    /**
     * invalidCharacter builds the "invalid character ..." ParseException for the
     * rune at $off, mirroring Go's newInvalidCharacterError. $where is a suffix
     * such as "at start of value".
     */
    public static function invalidCharacter(string $b, int $off, string $where): ParseException
    {
        [$r, $size] = self::decodeRune($b, $off);
        if ($r === 0xFFFD && $size === 1) {
            $what = sprintf("'\\x%02x'", ord($b[$off]));
        } elseif (self::isPrint($r)) {
            if ($r === 0x27) {
                $what = "'\\''";
            } elseif ($r === 0x5C) {
                $what = "'\\\\'";
            } else {
                $what = "'" . self::encodeRune($r) . "'";
            }
        } elseif ($r <= 0xFFFF) {
            $what = sprintf("'\\u%04x'", $r);
        } else {
            $what = sprintf("'\\U%08x'", $r);
        }
        return new ParseException('invalid character ' . $what . ' ' . $where, $off);
    }

    /** goQuote approximates Go's %q verb for the ASCII strings used in patch errors. */
    public static function goQuote(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            switch ($c) {
                case '"':
                    $out .= '\\"';
                    break;
                case '\\':
                    $out .= '\\\\';
                    break;
                case "\n":
                    $out .= '\\n';
                    break;
                case "\t":
                    $out .= '\\t';
                    break;
                case "\r":
                    $out .= '\\r';
                    break;
                default:
                    $o = ord($c);
                    $out .= $o < 0x20 ? sprintf('\\x%02x', $o) : $c;
            }
        }
        return $out . '"';
    }
}
