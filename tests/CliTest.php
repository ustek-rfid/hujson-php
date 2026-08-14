<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;

/** Exercises bin/hujsonfmt end to end. */
final class CliTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/hujsonfmt';
    private const FIXTURE = __DIR__ . '/fixtures/sample.hujson';

    /**
     * @param list<string> $args
     * @return array{out:string,err:string,code:int}
     */
    private function runCli(array $args, ?string $stdin = null): array
    {
        $cmd = array_merge([PHP_BINARY, self::BIN], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['out' => (string) $out, 'err' => (string) $err, 'code' => $code];
    }

    public function testDefaultFormat(): void
    {
        $src = (string) file_get_contents(self::FIXTURE);
        $r = $this->runCli([self::FIXTURE]);
        self::assertSame(0, $r['code']);
        self::assertSame(HuJSON::format($src), $r['out']);
    }

    public function testMinify(): void
    {
        $src = (string) file_get_contents(self::FIXTURE);
        $r = $this->runCli(['-m', self::FIXTURE]);
        self::assertSame(0, $r['code']);
        self::assertSame(HuJSON::minimize($src), $r['out']);
    }

    public function testStandardize(): void
    {
        $src = (string) file_get_contents(self::FIXTURE);
        $r = $this->runCli(['-s', self::FIXTURE]);
        self::assertSame(0, $r['code']);
        self::assertSame(HuJSON::standardize($src), $r['out']);
    }

    public function testStdinMinify(): void
    {
        $r = $this->runCli(['-m'], '{"a":1,}');
        self::assertSame(0, $r['code']);
        self::assertSame('{"a":1}', $r['out']);
    }

    public function testListShowsChangedThenNothing(): void
    {
        $r = $this->runCli(['-l', self::FIXTURE]);
        self::assertSame(0, $r['code']);
        self::assertSame(self::FIXTURE . "\n", $r['out']);

        // A formatted file yields no output.
        $tmp = tempnam(sys_get_temp_dir(), 'hj') . '.hujson';
        file_put_contents($tmp, HuJSON::format((string) file_get_contents(self::FIXTURE)));
        try {
            $r2 = $this->runCli(['-l', $tmp]);
            self::assertSame(0, $r2['code']);
            self::assertSame('', $r2['out']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testWriteInPlaceIsIdempotent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hj') . '.hujson';
        file_put_contents($tmp, (string) file_get_contents(self::FIXTURE));
        try {
            $r = $this->runCli(['-w', $tmp]);
            self::assertSame(0, $r['code']);
            $written = (string) file_get_contents($tmp);
            self::assertSame(HuJSON::format((string) file_get_contents(self::FIXTURE)), $written);

            // Re-running does not change the file.
            $this->runCli(['-w', $tmp]);
            self::assertSame($written, (string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function testDiff(): void
    {
        $r = $this->runCli(['-d', self::FIXTURE]);
        self::assertSame(0, $r['code']);
        self::assertStringContainsString('--- ' . self::FIXTURE . '.orig', $r['out']);
        self::assertStringContainsString('+{"a": 1, "b": [1, 2]}', $r['out']);

        // An already-formatted file yields no diff output.
        $tmp = tempnam(sys_get_temp_dir(), 'hj') . '.hujson';
        file_put_contents($tmp, HuJSON::format((string) file_get_contents(self::FIXTURE)));
        try {
            $r2 = $this->runCli(['-d', $tmp]);
            self::assertSame(0, $r2['code']);
            self::assertSame('', $r2['out']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testWriteWithStdinFails(): void
    {
        $r = $this->runCli(['-w'], '{}');
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('cannot use -w with standard input', $r['err']);
    }
}
