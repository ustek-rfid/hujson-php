<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;
use Ustek\HuJSON\HuJSONException;

/**
 * Differential test: runs a broad corpus (every input from the reference tables,
 * cross-applied across all four operations) through both the PHP facade and the
 * offline Go oracle (testref/), asserting byte-identical results and identical
 * error messages. Skipped when neither the built oracle nor a Go toolchain is
 * available.
 */
#[Group('differential')]
final class DifferentialTest extends TestCase
{
    private static ?string $oracle = null;
    private static bool $resolved = false;

    private static function oracle(): ?string
    {
        if (self::$resolved) {
            return self::$oracle;
        }
        self::$resolved = true;

        $dir = \dirname(__DIR__) . '/testref';
        $bin = $dir . '/oracle';
        if (!is_file($bin)) {
            // Try to build it if a Go toolchain is present.
            $goCheck = [];
            $code = 0;
            @exec('command -v go 2>/dev/null', $goCheck, $code);
            if ($code === 0 && $goCheck !== []) {
                @exec('cd ' . escapeshellarg($dir) . ' && go build -o oracle . 2>/dev/null', $o, $c);
            }
        }
        self::$oracle = is_file($bin) && is_executable($bin) ? $bin : null;
        return self::$oracle;
    }

    /**
     * @return array{0:bool,1:string} [ok, payload] where payload is bytes (ok) or
     *                                the error message (!ok)
     */
    private function runOracle(string $op, string $input): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hjd');
        file_put_contents($tmp, $input);
        try {
            $cmd = escapeshellarg((string) self::oracle()) . ' ' . escapeshellarg($op) . ' ' . escapeshellarg($tmp);
            $raw = shell_exec($cmd);
        } finally {
            @unlink($tmp);
        }
        $parts = explode("\0", (string) $raw, 2);
        return [$parts[0] === 'OK', $parts[1] ?? ''];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private function runPhp(string $op, string $input): array
    {
        try {
            $result = match ($op) {
                'parse' => HuJSON::parse($input)->pack(),
                'standardize' => HuJSON::standardize($input),
                'minimize' => HuJSON::minimize($input),
                'format' => HuJSON::format($input),
                default => throw new \InvalidArgumentException($op),
            };
            return [true, $result];
        } catch (HuJSONException $e) {
            return [false, $e->getMessage()];
        }
    }

    /** @return array<string,array{0:string,1:string}> [op, input] */
    public static function cases(): array
    {
        $seen = [];
        $inputs = [];
        foreach (['parse', 'format', 'patch'] as $section) {
            foreach (GoldenData::section($section) as $row) {
                foreach ([$row['in'], $row['patch'] ?? null] as $s) {
                    if ($s === null) {
                        continue;
                    }
                    $key = 'x' . base64_encode($s); // avoid numeric-string key coercion
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $inputs[] = $s;
                    }
                }
            }
        }

        $out = [];
        foreach ($inputs as $n => $input) {
            foreach (['parse', 'standardize', 'minimize', 'format'] as $op) {
                $out["$op#$n"] = [$op, $input];
            }
        }
        return $out;
    }

    #[DataProvider('cases')]
    public function testMatchesOracle(string $op, string $input): void
    {
        if (self::oracle() === null) {
            self::markTestSkipped('Go oracle unavailable (build testref/ or install Go)');
        }

        [$phpOk, $phpPayload] = $this->runPhp($op, $input);
        [$oraOk, $oraPayload] = $this->runOracle($op, $input);

        self::assertSame($oraOk, $phpOk, "success/failure parity for {$op} on " . base64_encode($input));
        self::assertSame($oraPayload, $phpPayload, "payload parity for {$op} on " . base64_encode($input));
    }
}
