<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/** FormatOptions controls how a single {@see Extra} run is formatted. */
final class FormatOptions
{
    public function __construct(
        public bool $ensureLeadingNewline = false,
        public bool $ensureTrailingNewline = false,
        public bool $removeLeadingEmptyLines = false,
        public bool $removeTrailingEmptyLines = false,
        public bool $unindentLastLine = false,
        public bool $appendSpaceIfEmpty = false,
    ) {
    }
}
