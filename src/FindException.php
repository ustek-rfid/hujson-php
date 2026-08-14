<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * FindException carries the partial {@see FindState} for a failed pointer
 * resolution. $notFound distinguishes Go's errNotFound (which Patch's "add"
 * tolerates at a final path segment) from an invalid-pointer error.
 *
 * @internal
 */
final class FindException extends \RuntimeException
{
    public function __construct(public FindState $state, public bool $notFound, string $message = 'value not found')
    {
        parent::__construct($message);
    }
}
