<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * ArrayValue is an exact syntactic representation of a JSON array
 * (Go's hujson.Array). A trailing comma is emitted only if the last element's
 * afterExtra is non-null.
 */
final class ArrayValue implements Composite
{
    /** @var list<Value> */
    public array $elements = [];

    public ?string $afterExtra = null;

    public function kind(): string
    {
        return Kind::ARRAY;
    }

    public function length(): int
    {
        return count($this->elements);
    }

    public function firstValue(): ?Value
    {
        return $this->elements === [] ? null : $this->elements[0];
    }

    public function lastValue(): ?Value
    {
        $n = count($this->elements);
        return $n === 0 ? null : $this->elements[$n - 1];
    }

    /** @return list<Value> */
    public function allValues(): array
    {
        return $this->elements;
    }

    public function getAt(int $i): ?ValueTrimmed
    {
        return $this->elements[$i]->value;
    }

    public function setAt(int $i, ?ValueTrimmed $v): void
    {
        $this->elements[$i]->value = $v;
    }

    public function insertAt(int $i, ?ValueTrimmed $v): void
    {
        $el = new Value();
        $el->value = $v;
        array_splice($this->elements, $i, 0, [$el]);
    }

    public function removeAt(int $i): ?ValueTrimmed
    {
        $v = $this->elements[$i]->value;
        array_splice($this->elements, $i, 1);
        return $v;
    }

    public function getBeforeExtraAt(int $i): ?string
    {
        return $i < count($this->elements) ? $this->elements[$i]->beforeExtra : $this->afterExtra;
    }

    public function setBeforeExtraAt(int $i, ?string $e): void
    {
        if ($i < count($this->elements)) {
            $this->elements[$i]->beforeExtra = $e;
        } else {
            $this->afterExtra = $e;
        }
    }

    public function getAfterExtra(): ?string
    {
        return $this->afterExtra;
    }

    public function setAfterExtra(?string $e): void
    {
        $this->afterExtra = $e;
    }

    public function cloneTrimmed(): ValueTrimmed
    {
        $a = new self();
        foreach ($this->elements as $e) {
            $a->elements[] = clone $e;
        }
        $a->afterExtra = $this->afterExtra;
        return $a;
    }
}
