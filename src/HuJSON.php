<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * HuJSON is the package facade, mirroring the package-level functions
 * hujson.Parse/Standardize/Minimize/Format.
 *
 * Unlike Go's Standardize/Minimize/Format (which return the original bytes plus
 * an error on failure), these throw a {@see HuJSONException} on a parse error.
 */
final class HuJSON
{
    private function __construct()
    {
    }

    /** parse parses a HuJSON value into a {@see Value} syntax tree. */
    public static function parse(string $src): Value
    {
        return (new Parser($src))->run();
    }

    /**
     * standardize strips HuJSON-specific features, replacing comments and trailing
     * commas with spaces to preserve line numbers and byte offsets.
     */
    public static function standardize(string $src): string
    {
        $v = self::parse($src);
        $v->standardize();
        return $v->pack();
    }

    /** minimize removes all whitespace, comments, and trailing commas. */
    public static function minimize(string $src): string
    {
        $v = self::parse($src);
        $v->minimize();
        return $v->pack();
    }

    /** format formats the value according to opinionated heuristics (like gofmt). */
    public static function format(string $src): string
    {
        $v = self::parse($src);
        $v->format();
        return $v->pack();
    }
}
