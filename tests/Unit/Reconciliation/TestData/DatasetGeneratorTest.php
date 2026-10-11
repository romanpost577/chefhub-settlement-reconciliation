<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\TestData;

use App\Reconciliation\TestData\DatasetGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatasetGeneratorTest extends TestCase
{
    public function testSameSeedGivesSameDataset(): void
    {
        self::assertEquals(new DatasetGenerator()->generate(500, 42), new DatasetGenerator()->generate(500, 42));
    }

    public function testDifferentSeedGivesDifferentDataset(): void
    {
        self::assertNotEquals(
            new DatasetGenerator()->generate(500, 42)->internalOrders,
            new DatasetGenerator()->generate(500, 43)->internalOrders,
        );
    }

    public function testDistributionIsRealistic(): void
    {
        $dataset = new DatasetGenerator()->generate(10_000, 42);
        $expected = $dataset->manifest['expected'];

        self::assertCount(10_000, $dataset->internalOrders);
        self::assertSame(300, $expected['missing_in_database']);
        self::assertSame(10, $expected['duplicate_rows']);
        self::assertEqualsWithDelta(500, $expected['missing_in_settlement'], 80);
        self::assertEqualsWithDelta(200, $expected['amount_mismatch'], 50);
        self::assertEqualsWithDelta(100, $expected['status_mismatch'], 35);
        self::assertEqualsWithDelta(100, $expected['duplicate_settlement'], 35);
        self::assertGreaterThan(8_000, $expected['matched_exact']);
        self::assertGreaterThan(50, $expected['matched_fuzzy']);
        $currencies = array_values(array_unique(array_column($dataset->internalOrders, 'currency')));
        sort($currencies);
        self::assertSame(['IDR', 'PHP', 'THB'], $currencies);
    }

    public function testRejectsInvalidOrderCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatasetGenerator()->generate(0, 42);
    }

    public function testRejectsTooManyOrdersForUniqueTimes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatasetGenerator()->generate(100_801, 42);
    }
}
