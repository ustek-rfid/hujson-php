<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * ValueTrimmed is a JSON value without surrounding whitespace or comments.
 * This is a sum type consisting of {@see Literal}, {@see ObjectValue}, or
 * {@see ArrayValue}.
 */
interface ValueTrimmed
{
    /** kind reports the kind of the JSON value (a {@see Kind} constant). */
    public function kind(): string;

    /** cloneTrimmed returns a deep copy of the value. */
    public function cloneTrimmed(): ValueTrimmed;
}
