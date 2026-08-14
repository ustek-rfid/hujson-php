<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\HuJSONException;

/** Ported from patch_test.go (TestPatch). */
final class PatchTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string,3:?string}> */
    public static function patchRows(): array
    {
        $out = [];
        foreach (GoldenData::section('patch') as $i => $row) {
            $out['patch#' . $i] = [$row['in'], $row['patch'], $row['want'], $row['err']];
        }
        return $out;
    }

    #[DataProvider('patchRows')]
    public function testPatch(string $in, string $patch, string $want, ?string $wantErr): void
    {
        $v = HuJSON::parse($in);

        $gotErr = null;
        try {
            $v->patch($patch);
        } catch (HuJSONException $e) {
            $gotErr = $e->getMessage();
        }

        self::assertSame($wantErr, $gotErr, 'patch error');

        // Go compares the result only when want is non-empty.
        if ($want !== '') {
            self::assertSame($want, (string) $v, 'patched result');
        }
    }
}
