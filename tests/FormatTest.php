<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\HuJSONException;

/** Ported from format_test.go (TestFormat, TestFormatErrors). */
final class FormatTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function formatRows(): array
    {
        $out = [];
        foreach (GoldenData::section('format') as $i => $row) {
            $out['format#' . $i] = [$row['in'], $row['want']];
        }
        return $out;
    }

    #[DataProvider('formatRows')]
    public function testFormat(string $in, string $want): void
    {
        $v = HuJSON::parse($in);
        $v->format();
        // Go: want := strings.TrimPrefix(tt.want, "\n") + "\n".
        $expected = (str_starts_with($want, "\n") ? substr($want, 1) : $want) . "\n";
        self::assertSame($expected, (string) $v);
    }

    /**
     * Go's Standardize/Minimize/Format return the original bytes plus an error on
     * a parse failure; the PHP facade throws instead.
     */
    public function testFormatErrors(): void
    {
        $bad = '[null,false,true,invalid]';
        foreach (['standardize', 'minimize', 'format'] as $method) {
            $threw = false;
            try {
                HuJSON::$method($bad);
            } catch (HuJSONException) {
                $threw = true;
            }
            self::assertTrue($threw, "HuJSON::{$method}() should throw on invalid input");
        }
    }
}
