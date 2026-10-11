<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Model;

use App\Reconciliation\Model\FxRates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FxRatesTest extends TestCase
{
    public function testConvertsMinorUnitsToUsd(): void
    {
        $rates = new FxRates();

        self::assertSame(172.0, $rates->toUsd(1_000_000, 'PHP'));
        self::assertSame(6.1, $rates->toUsd(10_000_000, 'IDR'));
        self::assertSame(2.85, $rates->toUsd(10_000, 'THB'));
    }

    public function testRejectsUnknownCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FxRates()->toUsd(100, 'EUR');
    }
}
