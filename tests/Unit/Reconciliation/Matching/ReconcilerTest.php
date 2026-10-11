<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Matching;

use App\Reconciliation\Matching\Reconciler;
use App\Reconciliation\Model\Discrepancy;
use App\Reconciliation\Model\DiscrepancyType;
use App\Reconciliation\Model\FxRates;
use App\Reconciliation\Model\MatchKind;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Priority;
use App\Reconciliation\Model\ReconciliationResult;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Model\Transaction;
use App\Tests\Unit\Reconciliation\TransactionFactory;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase
{
    use TransactionFactory;

    public function testExactMatchProducesNoDiscrepancy(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 10000)], [self::settlement('PA-1', 'CH-000001', 10000)]);

        self::assertSame([], $result->discrepancies);
        self::assertSame(1, $result->matchedCount(MatchKind::EXACT));
        self::assertSame(0, $result->matchedCount(MatchKind::FUZZY));
    }

    public function testOrderWithoutSettlementIsMissingInSettlement(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 10000)], []);

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::MISSING_IN_SETTLEMENT, $discrepancy->type);
        self::assertSame(10000, $discrepancy->impactMinor);
        self::assertNull($discrepancy->settlement);
    }

    public function testCancelledOrderWithoutSettlementIsFine(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 10000, PaymentStatus::CANCELLED)], []);

        self::assertSame([], $result->discrepancies);
        self::assertSame([], $result->matches);
    }

    public function testCancelledOrderThatSettledIsStatusMismatch(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, PaymentStatus::CANCELLED)],
            [self::settlement('PA-1', 'CH-000001', 10000)],
        );

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::STATUS_MISMATCH, $discrepancy->type);
        self::assertSame(10000, $discrepancy->impactMinor);
        self::assertSame([], $result->matches);
    }

    public function testSettlementWithoutOrderIsMissingInDatabase(): void
    {
        $result = $this->reconcile([], [self::settlement('PA-1', 'CH-900001', 7700, customerId: 'CU-P1')]);

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::MISSING_IN_DATABASE, $discrepancy->type);
        self::assertSame(7700, $discrepancy->impactMinor);
        self::assertNull($discrepancy->order);
    }

    public function testAmountDifferenceWithinToleranceIsMatched(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 10000)], [self::settlement('PA-1', 'CH-000001', 10005)]);

        self::assertSame([], $result->discrepancies);
        self::assertSame(1, $result->matchedCount(MatchKind::EXACT));
    }

    public function testAmountDifferenceAboveToleranceIsAmountMismatch(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 10000)], [self::settlement('PA-1', 'CH-000001', 9994)]);

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::AMOUNT_MISMATCH, $discrepancy->type);
        self::assertSame(6, $discrepancy->impactMinor);
        self::assertSame(MatchKind::EXACT, $discrepancy->matchKind);
        self::assertSame([], $result->matches);
    }

    public function testRefundNotSyncedIsStatusMismatch(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, PaymentStatus::REFUNDED)],
            [self::settlement('PA-1', 'CH-000001', 10000, PaymentStatus::PAID)],
        );

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::STATUS_MISMATCH, $discrepancy->type);
        self::assertSame('order is refunded, processor reports paid', $discrepancy->note);
    }

    public function testConsistentRefundIsMatched(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, PaymentStatus::REFUNDED)],
            [self::settlement('PA-1', 'CH-000001', 10000, PaymentStatus::REFUNDED)],
        );

        self::assertSame([], $result->discrepancies);
        self::assertSame(1, $result->matchedCount(MatchKind::EXACT));
    }

    public function testSecondSettlementIsDuplicateAndClosestAmountIsPrimary(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000)],
            [
                self::settlement('PA-1', 'CH-000001', 12000, time: '2026-10-02 09:00:00'),
                self::settlement('GW-1', 'CH-000001', 10000, time: '2026-10-02 11:00:00', source: Source::PROCESSOR_B),
            ],
        );

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::DUPLICATE_SETTLEMENT, $discrepancy->type);
        self::assertSame('PA-1', $discrepancy->settlement?->sourceId);
        self::assertSame(12000, $discrepancy->impactMinor);
        self::assertSame('GW-1', $result->matches[0]->settlement->sourceId);
    }

    public function testFuzzyMatchWithinWindow(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, customerId: 'CU-7', time: '2026-10-02 10:00:00')],
            [self::settlement('GW-1', null, 10000, customerId: 'CU-7', time: '2026-10-02 10:00:05', source: Source::PROCESSOR_B)],
        );

        self::assertSame([], $result->discrepancies);
        self::assertSame(1, $result->matchedCount(MatchKind::FUZZY));
    }

    public function testFuzzyMatchOutsideWindowFails(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, customerId: 'CU-7', time: '2026-10-02 10:00:00')],
            [self::settlement('GW-1', null, 10000, customerId: 'CU-7', time: '2026-10-02 10:00:06')],
        );

        self::assertSame(
            [DiscrepancyType::MISSING_IN_SETTLEMENT, DiscrepancyType::MISSING_IN_DATABASE],
            array_map(static fn (Discrepancy $d): DiscrepancyType => $d->type, $result->discrepancies),
        );
    }

    public function testFuzzyMatchIgnoresOrdersAlreadySettled(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, customerId: 'CU-7')],
            [
                self::settlement('PA-1', 'CH-000001', 10000, customerId: 'CU-7'),
                self::settlement('GW-1', null, 10000, customerId: 'CU-7', source: Source::PROCESSOR_B),
            ],
        );

        self::assertSame(DiscrepancyType::MISSING_IN_DATABASE, self::single($result)->type);
    }

    public function testAmbiguousFuzzyMatchIsNotMatchedAndWarns(): void
    {
        $result = $this->reconcile(
            [
                self::order('CH-000001', 10000, customerId: 'CU-7', time: '2026-10-02 10:00:00'),
                self::order('CH-000002', 10000, customerId: 'CU-7', time: '2026-10-02 10:00:04'),
            ],
            [self::settlement('GW-1', null, 10000, customerId: 'CU-7', time: '2026-10-02 10:00:02')],
        );

        self::assertCount(3, $result->discrepancies);
        self::assertSame(['ambiguous fuzzy match for processor_a GW-1: 2 candidate orders'], $result->warnings);
    }

    public function testOneOrderCanHaveStatusAndAmountMismatch(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, PaymentStatus::REFUNDED)],
            [self::settlement('PA-1', 'CH-000001', 15000)],
        );

        self::assertSame(
            [DiscrepancyType::STATUS_MISMATCH, DiscrepancyType::AMOUNT_MISMATCH],
            array_map(static fn (Discrepancy $d): DiscrepancyType => $d->type, $result->discrepancies),
        );
    }

    public function testCurrencyDifferenceIsAmountMismatch(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 10000, currency: 'PHP')],
            [self::settlement('PA-1', 'CH-000001', 10000, currency: 'THB')],
        );

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::AMOUNT_MISMATCH, $discrepancy->type);
        self::assertSame(10000, $discrepancy->impactMinor);
        self::assertSame('PHP', $discrepancy->currency);
        self::assertSame('currency differs: ordered PHP 100.00, settled THB 100.00', $discrepancy->note);
    }

    public function testSettlementImpactUsesSettlementCurrency(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 100000, PaymentStatus::CANCELLED, currency: 'PHP')],
            [self::settlement('PA-1', 'CH-000001', 10_000_000, currency: 'IDR')],
        );

        $status = $result->discrepancies[0];
        self::assertSame(DiscrepancyType::STATUS_MISMATCH, $status->type);
        self::assertSame('IDR', $status->currency);
        self::assertSame(6.1, $status->impactUsd);
    }

    public function testPrimarySettlementPrefersOrderCurrency(): void
    {
        $result = $this->reconcile(
            [self::order('CH-000001', 50000, currency: 'PHP')],
            [
                self::settlement('PA-1', 'CH-000001', 50000, time: '2026-10-02 09:00:00', currency: 'IDR'),
                self::settlement('PA-2', 'CH-000001', 50000, time: '2026-10-02 11:00:00', currency: 'PHP'),
            ],
        );

        $discrepancy = self::single($result);
        self::assertSame(DiscrepancyType::DUPLICATE_SETTLEMENT, $discrepancy->type);
        self::assertSame('PA-1', $discrepancy->settlement?->sourceId);
        self::assertSame('IDR', $discrepancy->currency);
        self::assertSame('PA-2', $result->matches[0]->settlement->sourceId);
    }

    public function testImpactIsConvertedToUsdWithPriority(): void
    {
        $result = $this->reconcile([self::order('CH-000001', 1_000_000)], []);

        $discrepancy = self::single($result);
        self::assertSame(172.0, $discrepancy->impactUsd);
        self::assertSame(Priority::HIGH, $discrepancy->priority);
    }

    public function testCountsSettlementsPerSource(): void
    {
        $result = $this->reconcile([], [
            self::settlement('PA-1', null, 1, customerId: 'CU-X'),
            self::settlement('GW-1', null, 1, customerId: 'CU-Y', source: Source::PROCESSOR_B),
            self::settlement('GW-2', null, 1, customerId: 'CU-Z', source: Source::PROCESSOR_B),
        ]);

        self::assertSame([
            'processor_a' => 1,
            'processor_b' => 2,
        ], $result->settlementCounts);
        self::assertSame(0, $result->orderCount);
    }

    /**
     * @param list<Transaction> $orders
     * @param list<Transaction> $settlements
     */
    private function reconcile(array $orders, array $settlements): ReconciliationResult
    {
        return new Reconciler(new FxRates())->reconcile($orders, $settlements);
    }

    private static function single(ReconciliationResult $result): Discrepancy
    {
        self::assertCount(1, $result->discrepancies);

        return $result->discrepancies[0];
    }
}
