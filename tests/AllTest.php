<?php

declare(strict_types=1);

namespace Ustek\HuJSON\Tests;

use PHPUnit\Framework\TestCase;
use Ustek\HuJSON\HuJSON;

/** Ported from types_test.go (TestAll). */
final class AllTest extends TestCase
{
    public function testAllDepthFirstOrder(): void
    {
        $v = HuJSON::parse('["fizz", {"key": ["value", {"foo": "bar"}]}, [1,2,3], "buzz"]');

        $got = [];
        foreach ($v->all() as $v2) {
            $got[] = (string) $v2;
        }

        $want = [
            '["fizz", {"key": ["value", {"foo": "bar"}]}, [1,2,3], "buzz"]',
            '"fizz"',
            ' {"key": ["value", {"foo": "bar"}]}',
            '"key"',
            ' ["value", {"foo": "bar"}]',
            '"value"',
            ' {"foo": "bar"}',
            '"foo"',
            ' "bar"',
            ' [1,2,3]',
            '1',
            '2',
            '3',
            ' "buzz"',
        ];

        self::assertSame($want, $got);
    }

    public function testRangeStopsEarly(): void
    {
        $v = HuJSON::parse('[1,2,3]');
        $seen = [];
        $result = $v->range(function ($node) use (&$seen): bool {
            $seen[] = (string) $node;
            return count($seen) < 2;
        });
        self::assertFalse($result);
        self::assertCount(2, $seen);
    }
}
