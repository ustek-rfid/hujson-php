<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * LineStats carries statistics about a sequence of lines.
 * multiline=false implies firstLength === lastLength.
 *
 * @internal
 */
final class LineStats
{
    public function __construct(
        public int $firstLength = 0,
        public int $lastLength = 0,
        public bool $multiline = false,
    ) {
    }
}
