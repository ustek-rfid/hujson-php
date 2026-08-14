<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * FindState tracks a JSON pointer resolution (port of find.go's findState).
 * pointer[0:offset] is the resolved prefix; pointer[offset:] is the remainder.
 *
 * @internal
 */
final class FindState
{
    public int $offset = 0;

    /** null for the root pointer. */
    public ?Composite $parent = null;

    /** name into parent to obtain the current value. */
    public string $name = '';

    /** index into parent to obtain the current value. */
    public int $idx = 0;

    /** the current value. */
    public ?Value $value = null;

    public function __construct(public string $pointer)
    {
    }
}
