<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Report;

use App\Reconciliation\Matching\Reconciler;
use App\Reconciliation\Model\FxRates;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Report\ChefPayoutCalculator;
use App\Reconciliation\Report\ReconciliationReport;
use App\Reconciliation\Report\ReportBuilder;
use App\Tests\Unit\Reconciliation\TransactionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Scenario: CH-000001 missing (PHP 100.00 → USD 1.72), CH-000002 fuzzy match,
 * CH-000003 amount mismatch IDR 30.00 (USD 0.00), CH-000004 status mismatch PHP 500.00 (USD 8.60),
 * CH-000005 exact match, PA-9 phantom THB 200.00 (USD 5.70).
 */
final class ReportBuilderTest extends TestCase
{
    use TransactionFactory;

    public function testSummaryCountsAndExposure(): void
    {
        $report = $this->build();

        self::assertSame(5, $report->summary['internal_orders']);
        self::assertSame([
            'processor_a' => 4,
            'processor_b' => 1,
        ], $report->summary['settlements']);
        self::assertSame(1, $report->summary['matched_exact']);
        self::assertSame(1, $report->summary['matched_fuzzy']);
        self::assertSame(4, $report->summary['total_discrepancies']);
        self::assertSame(
            [
                'count' => 1,
                'exposure_by_currency' => [
                    'PHP' => '100.00',
                ],
                'exposure_usd' => 1.72,
            ],
            $report->summary['by_type']['missing_in_settlement'],
        );
        self::assertSame(
            [
                'count' => 1,
                'exposure_by_currency' => [
                    'IDR' => '30.00',
                ],
                'exposure_usd' => 0.0,
            ],
            $report->summary['by_type']['amount_mismatch'],
        );
        self::assertSame(0, $report->summary['by_type']['duplicate_settlement']['count']);
        self::assertSame(16.02, $report->summary['total_exposure_usd']);
    }

    public function testDiscrepancyRowsAreSortedByUsdImpact(): void
    {
        $report = $this->build();

        self::assertSame(
            ['status_mismatch', 'missing_in_database', 'missing_in_settlement', 'amount_mismatch'],
            array_column($report->discrepancies, 'type'),
        );
        self::assertSame([
            'type' => 'status_mismatch',
            'priority' => 'low',
            'order_id' => 'CH-000004',
            'settlement_source' => 'processor_a',
            'settlement_id' => 'PA-4',
            'customer_id' => 'CU-4',
            'chef_id' => 'CF-2',
            'currency' => 'PHP',
            'order_amount' => '500.00',
            'settled_amount' => '500.00',
            'impact' => '500.00',
            'impact_usd' => 8.6,
            'match_kind' => 'exact',
            'note' => 'order is refunded, processor reports paid',
        ], $report->discrepancies[0]);
    }

    public function testInsightsPayoutsAndWarnings(): void
    {
        $report = $this->build();

        self::assertCount(4, $report->insights['top_discrepancies']);
        self::assertSame([
            'high' => 0,
            'medium' => 0,
            'low' => 4,
        ], $report->insights['by_priority']);
        self::assertSame(
            [
                'internal' => [
                    'count' => 1,
                    'exposure_usd' => 1.72,
                ],
            ],
            $report->insights['by_type_and_source']['missing_in_settlement'],
        );
        self::assertSame(['a.csv row 3: duplicate row PA-1'], $report->warnings);
        self::assertSame([
            'generated_at' => 'now',
        ], $report->meta);
        self::assertSame(
            [
                'chef_id' => 'CF-2',
                'currency' => 'PHP',
                'settled_gross' => '0.00',
                'commission' => '0.00',
                'payable_now' => '0.00',
                'on_hold' => '500.00',
                'discrepancy_count' => 1,
            ],
            $report->chefPayouts[0],
        );
    }

    public function testToArrayHasAllSections(): void
    {
        self::assertSame(
            ['meta', 'summary', 'insights', 'chef_payouts', 'discrepancies', 'warnings'],
            array_keys($this->build()->toArray()),
        );
    }

    private function build(): ReconciliationReport
    {
        $orders = [
            self::order('CH-000001', 10000, customerId: 'CU-1'),
            self::order('CH-000002', 20000, customerId: 'CU-2', time: '2026-10-02 11:00:00'),
            self::order('CH-000003', 100000, customerId: 'CU-3', currency: 'IDR'),
            self::order('CH-000004', 50000, PaymentStatus::REFUNDED, customerId: 'CU-4', chefId: 'CF-2'),
            self::order('CH-000005', 30000, customerId: 'CU-5'),
        ];
        $settlements = [
            self::settlement('PA-1', 'CH-000005', 30000, customerId: 'CU-5'),
            self::settlement(
                'GW-1',
                null,
                20000,
                customerId: 'CU-2',
                time: '2026-10-02 11:00:03',
                source: Source::PROCESSOR_B,
            ),
            self::settlement('PA-3', 'CH-000003', 103000, customerId: 'CU-3', currency: 'IDR'),
            self::settlement('PA-4', 'CH-000004', 50000, customerId: 'CU-4'),
            self::settlement('PA-9', 'CH-900001', 20000, customerId: 'CU-P1', currency: 'THB'),
        ];
        $result = new Reconciler(new FxRates())->reconcile($orders, $settlements);

        return new ReportBuilder()->build(
            $result,
            new ChefPayoutCalculator(new FxRates())->calculate($result),
            ['a.csv row 3: duplicate row PA-1'],
            [
                'generated_at' => 'now',
            ],
        );
    }
}
