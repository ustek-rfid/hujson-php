<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Kind reports the kind of a JSON value. Each kind is represented by a single
 * ASCII byte, which is conveniently the first byte of that kind's grammar with
 * the restriction that numbers are always represented with '0' (matching Go's
 * hujson.Kind). Compare with ===.
 */
final class Kind
{
    public const NULL = 'n';
    public const FALSE_ = 'f';
    public const TRUE_ = 't';
    public const STRING = '"';
    public const NUMBER = '0';
    public const OBJECT = '{';
    public const ARRAY = '[';
    public const INVALID = "\0";

    private function __construct()
    {
    }
}
