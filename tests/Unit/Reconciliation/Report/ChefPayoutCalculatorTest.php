<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Report;

use App\Reconciliation\Matching\Reconciler;
use App\Reconciliation\Model\FxRates;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Transaction;
use App\Reconciliation\Report\ChefPayout;
use App\Reconciliation\Report\ChefPayoutCalculator;
use App\Tests\Unit\Reconciliation\TransactionFactory;
use PHPUnit\Framework\TestCase;

final class ChefPayoutCalculatorTest extends TestCase
{
    use TransactionFactory;

    public function testCleanPaidOrdersArePayableMinusCommission(): void
    {
        $payouts = $this->payouts(
            [self::order('CH-000001', 10001, chefId: 'CF-1'), self::order('CH-000002', 20000, chefId: 'CF-1')],
            [self::settlement('PA-1', 'CH-000001', 10001), self::settlement('PA-2', 'CH-000002', 20000)],
        );

        self::assertEquals([new ChefPayout('CF-1', 'PHP', 30001, 4500, 25501, 0, 0)], $payouts);
    }

    public function testConsistentRefundsAreNotPaid(): void
    {
        $payouts = $this->payouts(
            [self::order('CH-000001', 10000, PaymentStatus::REFUNDED, chefId: 'CF-1')],
            [self::settlement('PA-1', 'CH-000001', 10000, PaymentStatus::REFUNDED)],
        );

        self::assertSame([], $payouts);
    }

    public function testOrdersWithDiscrepanciesAreOnHoldOnce(): void
    {
        $payouts = $this->payouts(
            [
                self::order('CH-000001', 10000, PaymentStatus::REFUNDED, chefId: 'CF-1'),
                self::order('CH-000002', 5000, chefId: 'CF-1'),
                self::order('CH-000003', 7000, chefId: 'CF-2'),
            ],
            [self::settlement('PA-1', 'CH-000001', 15000), self::settlement('PA-3', 'CH-000003', 7000)],
        );

        self::assertEquals([
            new ChefPayout('CF-1', 'PHP', 0, 0, 0, 15000, 3),
            new ChefPayout('CF-2', 'PHP', 7000, 1050, 5950, 0, 0),
        ], $payouts);
    }

    public function testSortsByOnHoldInUsdAcrossCurrencies(): void
    {
        $payouts = $this->payouts(
            [
                self::order('CH-000001', 100_000_000, chefId: 'CF-IDR', currency: 'IDR'),
                self::order('CH-000002', 1_000_000, chefId: 'CF-PHP', currency: 'PHP'),
            ],
            [],
        );

        self::assertSame(['CF-PHP', 'CF-IDR'], array_map(static fn (ChefPayout $p): string => $p->chefId, $payouts));
    }

    /**
     * @param list<Transaction> $orders
     * @param list<Transaction> $settlements
     *
     * @return list<ChefPayout>
     */
    private function payouts(array $orders, array $settlements): array
    {
        $result = new Reconciler(new FxRates())->reconcile($orders, $settlements);

        return new ChefPayoutCalculator(new FxRates())->calculate($result);
    }
}
