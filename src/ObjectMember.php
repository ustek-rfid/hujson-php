<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/** ObjectMember is a name/value pair within an {@see ObjectValue}. */
final class ObjectMember
{
    public function __construct(public Value $name, public Value $value)
    {
    }
}
