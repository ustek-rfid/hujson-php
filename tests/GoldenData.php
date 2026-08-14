<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

/**
 * GoldenData loads the reference test tables that were extracted verbatim from
 * the upstream Go test files (see testref/hujson/dump_test.go) into
 * tests/fixtures/golden.json. String fields are base64-encoded to carry exact
 * bytes; this helper decodes them.
 */
final class GoldenData
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return list<array<string,?string>> */
    public static function section(string $name): array
    {
        if (self::$data === null) {
            $raw = file_get_contents(__DIR__ . '/fixtures/golden.json');
            if ($raw === false) {
                throw new \RuntimeException('golden.json missing; run the dumper (testref/hujson/dump_test.go)');
            }
            self::$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        $rows = [];
        foreach (self::$data[$name] as $row) {
            $decoded = [];
            foreach ($row as $key => $value) {
                $decoded[$key] = $value === null ? null : base64_decode($value, true);
            }
            $rows[] = $decoded;
        }
        return $rows;
    }
}
