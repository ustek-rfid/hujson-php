<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * PackTrait provides serialization and offset maintenance for {@see Value}
 * (a port of pack.go).
 */
trait PackTrait
{
    /**
     * pack serializes the value as HuJSON. The output is byte-for-byte identical
     * to the input if no transformations were performed.
     */
    public function pack(): string
    {
        return $this->appendTo('');
    }

    public function __toString(): string
    {
        return $this->appendTo('');
    }

    private function appendTo(string $b): string
    {
        $b .= $this->beforeExtra ?? '';
        $v = $this->value;
        if ($v instanceof Literal) {
            $b .= $v->bytes;
        } elseif ($v instanceof ObjectValue) {
            $b .= '{';
            foreach ($v->members as $m) {
                $b = $m->name->appendTo($b);
                $b .= ':';
                $b = $m->value->appendTo($b);
                $b .= ',';
            }
            if ($v->length() > 0 && !Nodes::hasTrailingComma($v)) {
                $b = substr($b, 0, -1);
            }
            $b .= $v->afterExtra ?? '';
            $b .= '}';
        } elseif ($v instanceof ArrayValue) {
            $b .= '[';
            foreach ($v->elements as $e) {
                $b = $e->appendTo($b);
                $b .= ',';
            }
            if ($v->length() > 0 && !Nodes::hasTrailingComma($v)) {
                $b = substr($b, 0, -1);
            }
            $b .= $v->afterExtra ?? '';
            $b .= ']';
        }
        $b .= $this->afterExtra ?? '';
        return $b;
    }

    /**
     * updateOffsets iterates through the value and updates all startOffset and
     * endOffset fields so that they are accurate.
     */
    public function updateOffsets(): void
    {
        $this->updateOffsetsFrom(0);
    }

    private function updateOffsetsFrom(int $n): int
    {
        $n += strlen($this->beforeExtra ?? '');
        $this->startOffset = $n;
        $v = $this->value;
        if ($v instanceof Literal) {
            $n += strlen($v->bytes);
        } elseif ($v instanceof ObjectValue) {
            $n += 1; // "{"
            foreach ($v->members as $m) {
                $n = $m->name->updateOffsetsFrom($n);
                $n += 1; // ":"
                $n = $m->value->updateOffsetsFrom($n);
                $n += 1; // ","
            }
            if ($v->length() > 0 && !Nodes::hasTrailingComma($v)) {
                $n -= 1;
            }
            $n += strlen($v->afterExtra ?? '');
            $n += 1; // "}"
        } elseif ($v instanceof ArrayValue) {
            $n += 1; // "["
            foreach ($v->elements as $e) {
                $n = $e->updateOffsetsFrom($n);
                $n += 1; // ","
            }
            if ($v->length() > 0 && !Nodes::hasTrailingComma($v)) {
                $n -= 1;
            }
            $n += strlen($v->afterExtra ?? '');
            $n += 1; // "]"
        }
        $this->endOffset = $n;
        $n += strlen($this->afterExtra ?? '');
        return $n;
    }
}
