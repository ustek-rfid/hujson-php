<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Value is an exact syntactic representation of a JSON value. The starting and
 * ending byte offsets are populated when parsing but are otherwise ignored when
 * packing.
 *
 * A deep copy is produced with `clone $value` (Go's Value.Clone).
 *
 * Behaviour is split across traits mirroring the Go source files: pack.go
 * (PackTrait), standard.go (StandardizeTrait), format.go (FormatTrait),
 * find.go (FindTrait), and patch.go (PatchTrait).
 */
final class Value
{
    use PackTrait;
    use StandardizeTrait;
    use FormatTrait;
    use FindTrait;
    use PatchTrait;

    /**
     * Comments and whitespace before the value (after the preceding open brace,
     * open bracket, colon, comma, or start of input). null means "none".
     */
    public ?string $beforeExtra = null;

    /** Offset of the first byte of the value. */
    public int $startOffset = 0;

    /** The JSON value without surrounding whitespace or comments. */
    public ?ValueTrimmed $value = null;

    /** Offset of the next byte after the value. */
    public int $endOffset = 0;

    /**
     * Comments and whitespace after the value (before the succeeding colon,
     * comma, or end of input). null means "none".
     */
    public ?string $afterExtra = null;

    /** Deep-copies the wrapped value; extras are immutable strings. */
    public function __clone(): void
    {
        if ($this->value !== null) {
            $this->value = $this->value->cloneTrimmed();
        }
    }

    /**
     * all iterates through the value in depth-first order, starting with the
     * value itself. (Go's Value.All.)
     *
     * @return \Generator<int,Value>
     */
    public function all(): \Generator
    {
        yield $this;
        if ($this->value instanceof Composite) {
            foreach ($this->value->allValues() as $child) {
                yield from $child->all();
            }
        }
    }

    /**
     * range iterates through the value in depth-first order and calls $f for each
     * value (including the root). It stops when $f returns false. (Go's Value.Range.)
     *
     * @param callable(Value):bool $f
     */
    public function range(callable $f): bool
    {
        foreach ($this->all() as $v) {
            if (!$f($v)) {
                return false;
            }
        }
        return true;
    }
}
