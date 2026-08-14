<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * ObjectValue is an exact syntactic representation of a JSON object
 * (Go's hujson.Object). A trailing comma is emitted only if the last member
 * value's afterExtra is non-null.
 */
final class ObjectValue implements Composite
{
    /** @var list<ObjectMember> */
    public array $members = [];

    public ?string $afterExtra = null;

    public function kind(): string
    {
        return Kind::OBJECT;
    }

    public function length(): int
    {
        return count($this->members);
    }

    public function firstValue(): ?Value
    {
        return $this->members === [] ? null : $this->members[0]->name;
    }

    public function lastValue(): ?Value
    {
        $n = count($this->members);
        return $n === 0 ? null : $this->members[$n - 1]->value;
    }

    /** @return list<Value> */
    public function allValues(): array
    {
        $out = [];
        foreach ($this->members as $m) {
            $out[] = $m->name;
            $out[] = $m->value;
        }
        return $out;
    }

    public function getAt(int $i): ?ValueTrimmed
    {
        return $this->members[$i]->value->value;
    }

    public function setAt(int $i, ?ValueTrimmed $v): void
    {
        $this->members[$i]->value->value = $v;
    }

    public function insertAt(int $i, ?ValueTrimmed $v): void
    {
        $value = new Value();
        $value->value = $v;
        array_splice($this->members, $i, 0, [new ObjectMember(new Value(), $value)]);
    }

    public function removeAt(int $i): ?ValueTrimmed
    {
        $v = $this->members[$i]->value->value;
        array_splice($this->members, $i, 1);
        return $v;
    }

    public function getBeforeExtraAt(int $i): ?string
    {
        return $i < count($this->members) ? $this->members[$i]->name->beforeExtra : $this->afterExtra;
    }

    public function setBeforeExtraAt(int $i, ?string $e): void
    {
        if ($i < count($this->members)) {
            $this->members[$i]->name->beforeExtra = $e;
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
        $o = new self();
        foreach ($this->members as $m) {
            $o->members[] = new ObjectMember(clone $m->name, clone $m->value);
        }
        $o->afterExtra = $this->afterExtra;
        return $o;
    }
}
