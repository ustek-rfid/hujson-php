<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Extra holds static operations on comment/whitespace runs. In this port an
 * "Extra" is represented as a nullable string (`?string`), where null is Go's
 * nil slice and any string (including "") is a non-nil slice. The distinction is
 * load-bearing only for trailing-comma state (see {@see Nodes}).
 *
 * @internal
 */
final class Extra
{
    /** consumeWhitespace returns the number of ASCII whitespace bytes at $off. */
    public static function consumeWhitespace(string $b, int $off): int
    {
        $len = strlen($b);
        $n = 0;
        while ($off + $n < $len) {
            $c = $b[$off + $n];
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $n++;
            } else {
                break;
            }
        }
        return $n;
    }

    /**
     * consumeComment consumes a line ("//".."\n") or block ("/*".."* /") comment
     * starting at $off. It returns the length of the comment if valid, 0 if it is
     * not a comment, and -1 if it is unterminated.
     */
    public static function consumeComment(string $b, int $off): int
    {
        $two = substr($b, $off, 2);
        if ($two === '//') {
            $end = "\n";
        } elseif ($two === '/*') {
            $end = '*/';
        } else {
            return 0;
        }
        $abs = strpos($b, $end, $off + 2);
        if ($abs === false) {
            return -1;
        }
        return $abs - $off + strlen($end);
    }

    /**
     * indexLineSeparator returns the byte index of the first U+2028 or U+2029 in
     * $sub, or -1 if neither is present.
     */
    public static function indexLineSeparator(string $sub): int
    {
        $i = strpos($sub, "\xE2\x80\xA8"); // U+2028
        $j = strpos($sub, "\xE2\x80\xA9"); // U+2029
        $i = $i === false ? -1 : $i;
        $j = $j === false ? -1 : $j;
        if ($i < 0 || ($j >= 0 && $j < $i)) {
            return $j;
        }
        return $i;
    }

    /** hasComment reports whether the extra contains a comment. */
    public static function hasComment(?string $b): bool
    {
        return $b !== null && self::consumeWhitespace($b, 0) < strlen($b);
    }

    /** isStandard reports whether this is standard JSON whitespace (no comments). */
    public static function isStandard(?string $b): bool
    {
        return !self::hasComment($b);
    }

    /** hasNewline reports whether the extra contains a newline. */
    public static function hasNewline(?string $b): bool
    {
        return $b !== null && str_contains($b, "\n");
    }

    /**
     * isValid reports whether the whitespace and comments are valid according to
     * the HuJSON grammar (Go's Extra.IsValid).
     */
    public static function isValid(?string $b): bool
    {
        $s = $b ?? '';
        try {
            return Parser::consumeExtra($s, 0) === strlen($s);
        } catch (ParseException) {
            return false;
        }
    }

    /**
     * standardize replaces every non-whitespace byte with a space, preserving
     * newlines to keep line numbers stable. null is preserved as null.
     */
    public static function standardize(?string $b): ?string
    {
        if ($b === null) {
            return null;
        }
        $len = strlen($b);
        for ($i = 0; $i < $len; $i++) {
            $c = $b[$i];
            if ($c !== ' ' && $c !== "\t" && $c !== "\r" && $c !== "\n") {
                $b[$i] = ' ';
            }
        }
        return $b;
    }

    /**
     * lineStats returns statistics about the comment/whitespace run (port of
     * Go's Extra.lineStats).
     */
    public static function lineStats(?string $b): LineStats
    {
        $b ??= '';
        $length = static function (string $s): int {
            $n = 0;
            while (true) {
                $s = substr($s, self::consumeWhitespace($s, 0));
                if (str_starts_with($s, '//')) {
                    return $n + 1 + strlen($s); // line comment must go to the end
                }
                if (str_starts_with($s, '/*')) {
                    $nc = self::consumeComment($s, 0);
                    if ($nc <= 0) {
                        return $n + 1 + strlen($s); // truncated block comment
                    }
                    $n += 1 + $nc;
                    $s = substr($s, $nc);
                    continue;
                }
                if ($n > 0) {
                    $n += 1; // padding space after block comment
                }
                return $n;
            }
        };
        if (!str_contains($b, "\n")) {
            $n = $length($b);
            return new LineStats($n, $n, false);
        }
        $first = substr($b, 0, (int) strpos($b, "\n"));
        $last = substr($b, (int) strrpos($b, "\n") + 1);
        return new LineStats($length($first), $length($last), true);
    }

    /**
     * format reformats a comment/whitespace run for consistent indentation and
     * spacing (port of Go's (*Extra).format). It returns the new value; null is
     * preserved when the result is byte-equal to the (nil) input.
     */
    public static function format(?string $b, int $depth, FormatOptions $opts): ?string
    {
        // Remove carriage returns to normalize output across operating systems.
        $star = $b;
        if ($star !== null && str_contains($star, "\r")) {
            $star = str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", ' '], $star);
        }

        $in = $star ?? '';
        $out = '';

        // Inject a leading newline if not present in the input.
        if ($opts->ensureLeadingNewline && !str_contains($in, "\n")) {
            $out .= "\n";
        }

        // Iterate over every paragraph in the comment.
        while ($in !== '') {
            // Handle whitespace.
            $nw = self::consumeWhitespace($in, 0);
            if ($nw > 0) {
                $nl = substr_count(substr($in, 0, $nw), "\n");
                if ($nl > 2) {
                    $nl = 2; // never allow more than one blank line
                }
                $out .= str_repeat("\n", $nl);
                $in = substr($in, $nw);
                continue;
            }

            // Handle comments.
            $n = self::consumeComment($in, 0);
            if ($n <= 0) {
                return $star; // invalid comment
            }

            // Emit leading whitespace.
            if (str_ends_with($out, "\n")) {
                $out .= str_repeat("\t", max(0, $depth));
            } else {
                $out .= ' ';
            }

            // Copy single-line comment to the output verbatim.
            $comment = substr($in, 0, $n);
            if (str_starts_with($comment, '//') || !str_contains($comment, "\n")) {
                $comment = Chars::rtrimSpace($comment); // trim trailing whitespace
                if (str_starts_with($comment, '//')) {
                    $n--; // leave newline for next iteration of comment
                }
                $out .= $comment;
                $in = substr($in, $n);
                continue;
            }

            // Format multi-line block comments and copy to the output.
            $lines = explode("\n", $comment); // >= 2 entries since a newline exists
            $firstLine = '';
            $hasEmptyLine = false;
            $cnt = count($lines);
            for ($i = 0; $i < $cnt; $i++) {
                $line = Chars::rtrimSpace($lines[$i]);
                if ($firstLine === '' && $line !== '' && $i > 0) {
                    $firstLine = $line;
                }
                $hasEmptyLine = $hasEmptyLine || $line === '';
                $lines[$i] = $line;
            }

            // Compute the longest common prefix.
            $commonPrefix = $firstLine;
            for ($k = 1; $k < $cnt; $k++) {
                $line = $lines[$k];
                if ($line === '') {
                    continue; // ignore empty lines
                }
                // If the last line is just "*/" with preceding whitespace, then
                // ignore that whitespace and copy the common prefix instead.
                if (str_ends_with($line, '*/') && self::consumeWhitespace($line, 0) + 2 === strlen($line)) {
                    $prefixLen = self::consumeWhitespace($commonPrefix, 0);
                    $lines[$k] = substr($commonPrefix, 0, $prefixLen) . '*/';
                    break;
                }
                $m = min(strlen($line), strlen($commonPrefix));
                for ($j = 0; $j < $m; $j++) {
                    if ($line[$j] !== $commonPrefix[$j]) {
                        $commonPrefix = substr($commonPrefix, 0, $j);
                        break;
                    }
                }
            }

            // Indent every line and copy to output.
            $prefixLen = self::consumeWhitespace($commonPrefix, 0);
            $starAligned = !$hasEmptyLine && strlen($commonPrefix) > $prefixLen && $commonPrefix[$prefixLen] === '*';
            $out .= $lines[0];
            $out .= "\n";
            for ($k = 1; $k < $cnt; $k++) {
                $line = $lines[$k];
                if ($line !== '') {
                    $out .= str_repeat("\t", max(0, $depth));
                    if ($starAligned) {
                        $out .= ' ';
                    }
                    $out .= substr($line, $prefixLen);
                }
                $out .= "\n";
            }
            $out = rtrim($out, "\n");
            $in = substr($in, $n);
        }

        // Inject a trailing newline if not present in the input.
        if ($opts->ensureTrailingNewline && !str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        // Remove all leading empty lines.
        while ($opts->removeLeadingEmptyLines && str_starts_with($out, "\n\n")) {
            $out = substr($out, 1);
        }
        // Remove all trailing empty lines.
        while ($opts->removeTrailingEmptyLines && str_ends_with($out, "\n\n")) {
            $out = substr($out, 0, -1);
        }
        // If the whitespace ends on a newline, append indentation; otherwise emit
        // a space if we did not end on a newline.
        if (str_ends_with($out, "\n")) {
            $d = $opts->unindentLastLine ? $depth - 1 : $depth;
            $out .= str_repeat("\t", max(0, $d));
        } elseif ($out !== '') {
            $out .= ' ';
        }
        // Emit a space if the output is empty.
        if ($opts->appendSpaceIfEmpty && $out === '') {
            $out .= ' ';
        }

        // Preserve null when byte-equal to the (nil) input (Go's bytes.Equal).
        return (($star ?? '') === $out) ? $star : $out;
    }

    /**
     * classifyComments splits comments into those belonging to the previous
     * element (b[0:prevEnd]) and those belonging to the current element
     * (b[currStart:]). Invariant: prevEnd <= currStart.
     *
     * @return array{0:int,1:int}
     */
    public static function classifyComments(?string $b): array
    {
        $b ??= '';
        $len = strlen($b);
        $firstDivider = 0;
        $lastDivider = 0;
        $numDividers = 0;
        $n = 0;
        $prevNewline = 0;
        while ($len > $n) {
            $nw = self::consumeWhitespace($b, $n);
            if ($prevNewline + substr_count(substr($b, $n, $nw), "\n") >= 2) {
                if ($numDividers === 0) {
                    $firstDivider = $n;
                }
                $lastDivider = $n;
                $numDividers++;
            }
            $n += $nw;
            $nc = self::consumeComment($b, $n);
            if ($nc <= 0) {
                break;
            }
            $prevNewline = 0;
            if (str_ends_with(substr($b, $n, $nc), "\n")) {
                $prevNewline = 1;
            }
            $n += $nc;
        }

        if ($numDividers === 0) {
            $nw = self::consumeWhitespace($b, 0);
            $nc = self::consumeComment($b, $nw);
            $n = $nw + $nc;
            if ($n >= 0 && substr_count(substr($b, 0, $n), "\n") === 1 && str_ends_with(substr($b, 0, $n), "\n")) {
                return [$n, $n];
            }
            return [0, 0];
        }
        return [$firstDivider, $lastDivider];
    }

    /** injectLeadingComments injects leading comments into the bottom of $b. */
    public static function injectLeadingComments(?string $b, ?string $leading): ?string
    {
        if (($leading ?? '') === '') {
            return $b; // unchanged; preserve null
        }
        $cur = $b ?? '';
        [, $currStart] = self::classifyComments($cur);
        $blankLen = self::consumeWhitespace($cur, $currStart);
        $cur = substr($cur, 0, $currStart + $blankLen);
        $lead = substr($leading, self::consumeWhitespace($leading, 0));
        if ($lead !== '') {
            $i = strrpos($cur, "\n");
            if ($i === false || self::hasComment(substr($cur, $i))) {
                $cur .= "\n";
            }
            $cur .= $lead;
        }
        return $cur;
    }

    /**
     * extractLeadingComments extracts leading comments from the bottom of $b.
     * If $readonly, the source is not mutated.
     *
     * @return array{0:?string,1:?string} [newSource, leading]
     */
    public static function extractLeadingComments(?string $b, bool $readonly): array
    {
        $cur = $b ?? '';
        [, $currStart] = self::classifyComments($cur);
        $blankLen = self::consumeWhitespace($cur, $currStart);
        $ext = substr($cur, $currStart + $blankLen);
        $leading = $ext === '' ? null : $ext;
        $newB = $b;
        if (!$readonly) {
            $newB = $b === null ? null : substr($cur, 0, $currStart + $blankLen);
        }
        return [$newB, $leading];
    }

    /** injectTrailingComments injects trailing comments into the top of $b. */
    public static function injectTrailingComments(?string $b, ?string $trailing): ?string
    {
        if (($trailing ?? '') === '') {
            return $b; // unchanged; preserve null
        }
        $cur = $b ?? '';
        [$prevEnd] = self::classifyComments($cur);
        if (str_ends_with(substr($cur, 0, $prevEnd), "\n")) {
            $prevEnd--; // preserve trailing newline
        }
        $cur = substr($cur, $prevEnd);
        $t = $trailing;
        if (self::hasComment($t)) {
            if (str_ends_with($t, "\n") && str_starts_with($cur, "\n")) {
                $t = substr($t, 0, -1); // drop trailing newline
            }
            $cur = $t . $cur;
        }
        return $cur;
    }

    /**
     * extractTrailingComments extracts trailing comments from the top of $b.
     * If $readonly, the source is not mutated.
     *
     * @return array{0:?string,1:?string} [newSource, trailing]
     */
    public static function extractTrailingComments(?string $b, bool $readonly): array
    {
        $cur = $b ?? '';
        [$prevEnd] = self::classifyComments($cur);
        $trailingStr = substr($cur, 0, $prevEnd);
        $trailing = $trailingStr === '' ? null : $trailingStr;
        $newB = $b;
        if (!$readonly) {
            $pe = $prevEnd;
            if (str_ends_with($trailingStr, "\n")) {
                $pe--; // preserve trailing newline
            }
            $newB = $b === null ? null : substr($cur, $pe);
        }
        return [$newB, $trailing];
    }
}
