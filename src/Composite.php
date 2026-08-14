<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Composite are the common operations of {@see ObjectValue} and {@see ArrayValue}.
 * The getBeforeExtraAt/setBeforeExtraAt and getAfterExtra/setAfterExtra pairs
 * replace Go's `*Extra` out-parameters (beforeExtraAt/afterExtra).
 */
interface Composite extends ValueTrimmed
{
    public function length(): int;

    public function firstValue(): ?Value;

    /** @return list<Value> */
    public function allValues(): array;

    public function lastValue(): ?Value;

    public function getAt(int $i): ?ValueTrimmed;

    public function setAt(int $i, ?ValueTrimmed $v): void;

    public function insertAt(int $i, ?ValueTrimmed $v): void;

    public function removeAt(int $i): ?ValueTrimmed;

    public function getBeforeExtraAt(int $i): ?string;

    public function setBeforeExtraAt(int $i, ?string $e): void;

    public function getAfterExtra(): ?string;

    public function setAfterExtra(?string $e): void;
}
