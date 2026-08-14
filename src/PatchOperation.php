<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * PatchOperation is a single parsed RFC 6902 operation.
 *
 * @internal
 */
final class PatchOperation
{
    /** "add" | "remove" | "replace" | "move" | "copy" | "test" */
    public string $op = '';

    /** Used by all operations. */
    public string $path = '';

    /** Used by "move" and "copy". */
    public string $from = '';

    /** Used by "add", "replace", and "test". */
    public ?Value $value = null;
}
