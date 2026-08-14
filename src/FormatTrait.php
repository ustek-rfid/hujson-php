<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * FormatTrait provides Format and its helpers for {@see Value} (a port of
 * format.go). Since PHP has no `*Extra` out-parameters, every Go `X.format(...)`
 * becomes `X = Extra::format(X, ...)` assigned back through the handle.
 */
trait FormatTrait
{
    private const PUNCH_CARD_WIDTH = 80;

    /**
     * format formats the value according to opinionated heuristics (the HuJSON
     * equivalent of gofmt). It is idempotent and keeps standard JSON standard.
     */
    public function format(): void
    {
        // Format leading extra.
        $this->beforeExtra = Extra::format($this->beforeExtra, 0, new FormatOptions());
        if ($this->beforeExtra !== null) {
            // Never has leading whitespace.
            $this->beforeExtra = substr($this->beforeExtra, Extra::consumeWhitespace($this->beforeExtra, 0));
        }

        // Format the value.
        $needExpand = new \WeakMap();
        $isStandard = $this->isStandard();
        $this->normalize();
        $this->expandComposites($needExpand);
        $this->formatWhitespace(0, $needExpand, $isStandard);
        $this->alignObjectValues();

        // Format trailing extra: always exactly one trailing newline.
        $af = Extra::format($this->afterExtra, 0, new FormatOptions());
        $this->afterExtra = Chars::rtrimSpace($af ?? '') . "\n";

        $this->updateOffsets();
    }

    /**
     * normalize performs simple normalization: normalize strings, collapse empty
     * objects/arrays, and drop whitespace between name/colon and value/comma.
     */
    private function normalize(): void
    {
        $v = $this->value;
        if ($v instanceof Literal) {
            if ($v->kind() === Kind::STRING && str_contains($v->bytes, '\\')) {
                $this->value = Literal::fromString($v->asString());
            }
            return;
        }
        if (!($v instanceof Composite)) {
            return;
        }

        // Cleanup for empty objects and arrays.
        if ($v->length() === 0) {
            if (!Extra::hasComment($v->getAfterExtra())) {
                $v->setAfterExtra(null);
            }
            return;
        }

        // Drop whitespace between name and colon, or between value and comma.
        foreach ($v->allValues() as $v3) {
            if (!Extra::hasComment($v3->afterExtra)) {
                $v3->afterExtra = null;
            }
        }
        // Normalize all sub-values.
        foreach ($v->allValues() as $v3) {
            $v3->normalize();
        }
    }

    /**
     * expandComposites populates $needExpand with the set of composite values
     * that must be expanded (each member/element on its own line). Pure: it does
     * not mutate the AST.
     */
    private function expandComposites(\WeakMap $needExpand): LineStats
    {
        $v = $this->value;
        if ($v instanceof Literal) {
            $len = strlen($v->bytes);
            return new LineStats($len, $len, false);
        }
        if (!($v instanceof Composite)) {
            return new LineStats();
        }

        $expand = false;
        $lineLength = 0;
        $lineLengths = [];
        $updateStats = static function (LineStats $s) use (&$lineLength, &$lineLengths): void {
            $lineLength += $s->firstLength;
            if ($s->multiline) {
                $lineLengths[] = $lineLength;
                $lineLength = $s->lastLength;
            }
        };

        if ($v instanceof ObjectValue) {
            $lineLength += 1; // "{"
            foreach ($v->members as $m) {
                $name = $m->name;
                $value = $m->value;
                $expand = $expand || Extra::hasNewline($name->beforeExtra);
                $updateStats(Extra::lineStats($name->beforeExtra));
                $updateStats($name->expandComposites($needExpand));
                $updateStats(Extra::lineStats($name->afterExtra));
                $lineLength += 2; // ": "
                $updateStats(Extra::lineStats($value->beforeExtra));
                $updateStats($value->expandComposites($needExpand));
                $updateStats(Extra::lineStats($value->afterExtra));
                $lineLength += 2; // ", "
            }
            $lineLength += 1; // "}"
            // Mirrors Go: stats.multiline is the (still-zero) return value here.
            $expand = $expand || ($v->length() > 1 && false);
        } else {
            /** @var ArrayValue $v */
            $lineLength += 1; // "["
            foreach ($v->elements as $value) {
                $expand = $expand || Extra::hasNewline($value->beforeExtra);
                $updateStats(Extra::lineStats($value->beforeExtra));
                $updateStats($value->expandComposites($needExpand));
                $updateStats(Extra::lineStats($value->afterExtra));
                $lineLength += 2; // ", "
            }
            $lineLength += 1; // "]"
        }

        $last = $v->lastValue();
        if ($last !== null) {
            $expand = $expand || Extra::hasNewline($last->afterExtra);
        }
        $expand = $expand || Extra::hasNewline($v->getAfterExtra());

        // Update the block statistics.
        $lineLengths[] = $lineLength;
        $stats = new LineStats($lineLengths[0], $lineLengths[count($lineLengths) - 1], count($lineLengths) > 1);
        for ($i = 0; !$expand && $i < count($lineLengths); $i++) {
            $expand = $lineLengths[$i] > self::PUNCH_CARD_WIDTH;
        }

        if ($expand) {
            $stats = new LineStats(1, 1, true);
            $stats->firstLength += Extra::lineStats($v->getBeforeExtraAt(0))->firstLength;
            $needExpand[$v] = true;
        }
        return $stats;
    }

    /**
     * formatWhitespace mutates the AST to ensure consistent indentation and
     * expansion of objects and arrays.
     */
    private function formatWhitespace(int $depth, \WeakMap $needExpand, bool $standardize): void
    {
        $comp = $this->value;
        if (!($comp instanceof Composite)) {
            return;
        }
        $expand = isset($needExpand[$comp]);

        if ($comp instanceof ObjectValue) {
            foreach ($comp->members as $i => $m) {
                $name = $m->name;
                $value = $m->value;

                // Format extra before name.
                $name->beforeExtra = Extra::format($name->beforeExtra, $depth + 1, new FormatOptions(
                    ensureLeadingNewline: $expand,
                    removeLeadingEmptyLines: $i === 0,
                    appendSpaceIfEmpty: $i !== 0,
                ));
                // Format the name.
                $name->formatWhitespace($depth + 1, $needExpand, $standardize);
                // Format extra after name and before colon.
                $name->afterExtra = Extra::format($name->afterExtra, $depth + 2, new FormatOptions(
                    removeLeadingEmptyLines: true,
                    removeTrailingEmptyLines: true,
                ));
                // Format extra after colon and before value.
                $value->beforeExtra = Extra::format($value->beforeExtra, $depth + 2, new FormatOptions(
                    removeLeadingEmptyLines: true,
                    removeTrailingEmptyLines: true,
                    appendSpaceIfEmpty: true,
                ));
                // Format the value.
                $depthOffset = 0;
                if ($expand) {
                    $depthOffset++;
                }
                if (Extra::hasNewline($name->afterExtra) || Extra::hasNewline($value->beforeExtra)) {
                    $depthOffset++;
                }
                $value->formatWhitespace($depth + $depthOffset, $needExpand, $standardize);
                // Format extra after value and before comma.
                $value->afterExtra = Extra::format($value->afterExtra, $depth + 2, new FormatOptions(
                    removeLeadingEmptyLines: true,
                    removeTrailingEmptyLines: true,
                ));
            }
        } else {
            /** @var ArrayValue $comp */
            foreach ($comp->elements as $i => $value) {
                // Format extra before value.
                $value->beforeExtra = Extra::format($value->beforeExtra, $depth + 1, new FormatOptions(
                    ensureLeadingNewline: $expand,
                    removeLeadingEmptyLines: $i === 0,
                    appendSpaceIfEmpty: $i !== 0,
                ));
                // Format the value.
                $depthOffset = 0;
                if ($expand) {
                    $depthOffset++;
                }
                $value->formatWhitespace($depth + $depthOffset, $needExpand, $standardize);
                // Format extra after value and before comma.
                $value->afterExtra = Extra::format($value->afterExtra, $depth + 2, new FormatOptions(
                    removeLeadingEmptyLines: true,
                    removeTrailingEmptyLines: true,
                ));
            }
        }

        // Format the extra before the closing '}' or ']'.
        $comp->setAfterExtra(Extra::format($comp->getAfterExtra(), $depth + 1, new FormatOptions(
            ensureTrailingNewline: $expand,
            removeLeadingEmptyLines: $comp->length() === 0,
            removeTrailingEmptyLines: true,
            unindentLastLine: true,
        )));

        // Normalize presence of trailing comma.
        $last = $comp->lastValue();
        $surroundedComma = $last !== null
            && strlen($last->afterExtra ?? '') > 0
            && strlen($comp->getAfterExtra() ?? '') > 0;
        if (!$expand && !$surroundedComma) {
            // Avoid a trailing comma for a non-expanded object or array.
            Nodes::setTrailingComma($comp, false);
        } elseif ($expand && !$standardize) {
            // Otherwise emit a trailing comma (unless output must be standard).
            Nodes::setTrailingComma($comp, true);
        }
    }

    /**
     * alignObjectValues aligns object values by inserting spaces after the name
     * so values line up to the same column.
     */
    private function alignObjectValues(): void
    {
        $v = $this->value;
        if ($v instanceof ObjectValue) {
            /** @var list<array{0:Value,1:int}> $rows */
            $rows = [];
            $alignRows = static function () use (&$rows): void {
                $max = 0;
                foreach ($rows as $row) {
                    if ($max < $row[1]) {
                        $max = $row[1];
                    }
                }
                foreach ($rows as $row) {
                    $pad = $max - $row[1];
                    if ($pad > 0) {
                        $row[0]->beforeExtra = ($row[0]->beforeExtra ?? '') . str_repeat(' ', $pad);
                    }
                }
                $rows = [];
            };

            $indentSuffix = ''; // vestigial in the reference; always empty
            foreach ($v->members as $m) {
                $name = $m->name;
                $value = $m->value;

                // The whitespace right before the name must have a newline, and
                // everything after the name until the comma must not.
                if (
                    !Extra::hasNewline($name->beforeExtra)
                    || $name->hasNewline(false)
                    || Extra::hasNewline($name->afterExtra)
                    || Extra::hasNewline($value->beforeExtra)
                    || $value->hasNewline(false)
                    || Extra::hasNewline($value->afterExtra)
                ) {
                    $alignRows();
                    continue;
                }

                // Multiple newlines start a new block of rows to align.
                if (
                    substr_count($name->beforeExtra ?? '', "\n") > 1
                    || !str_ends_with($name->beforeExtra ?? '', $indentSuffix)
                ) {
                    $alignRows();
                }

                $nameLit = $name->value;
                $length = strlen($nameLit instanceof Literal ? $nameLit->bytes : '')
                    + strlen($name->afterExtra ?? '')
                    + 1 // ":"
                    + strlen($value->beforeExtra ?? '');
                $rows[] = [$value, $length];
            }
            $alignRows();
        }

        // Recursively align all sub-objects.
        if ($v instanceof Composite) {
            foreach ($v->allValues() as $v2) {
                $v2->alignObjectValues();
            }
        }
    }

    /** hasNewline reports whether the value (and optionally its own extras) spans lines. */
    public function hasNewline(bool $checkTopLevelExtra): bool
    {
        if ($checkTopLevelExtra && (Extra::hasNewline($this->beforeExtra) || Extra::hasNewline($this->afterExtra))) {
            return true;
        }
        if ($this->value instanceof Composite) {
            foreach ($this->value->allValues() as $v) {
                if ($v->hasNewline(true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
