<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * ParseSentinel is thrown by {@see Parser::parseNextTrimmed()} when it encounters
 * a '}' or ']' at the start of a value. It mirrors Go's errInvalidObjectEnd /
 * errInvalidArrayEnd sentinels, which are caught only by the enclosing object or
 * array loop to detect the end of a composite (including a trailing comma).
 *
 * @internal
 */
class ParseSentinel extends ParseException
{
    public ?Value $partial = null;

    public function __construct(string $message, int $offset, public string $kind)
    {
        parent::__construct($message, $offset);
    }
}
