<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Ingestion;

use App\Reconciliation\Ingestion\OrderRefNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderRefNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function refs(): iterable
    {
        yield 'canonical' => ['CH-000123', 'CH-000123'];
        yield 'lowercase short' => ['ch123', 'CH-000123'];
        yield 'no dash' => ['CH000123', 'CH-000123'];
        yield 'dash and spaces' => [' ch-123 ', 'CH-000123'];
        yield 'six digits' => ['CH-900001', 'CH-900001'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['ORDER#12', null];
        yield 'too long' => ['CH-1234567', null];
    }

    #[DataProvider('refs')]
    public function testNormalize(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, OrderRefNormalizer::normalize($raw));
    }
}
