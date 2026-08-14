<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * ParseException is an internal error carrying the byte offset at which the
 * error occurred, so {@see Parser::run()} can translate it into a line/column
 * {@see HuJSONException}. Not part of the public API.
 *
 * @internal
 */
class ParseException extends \RuntimeException
{
    public function __construct(string $message, public int $offset)
    {
        parent::__construct($message);
    }
}
