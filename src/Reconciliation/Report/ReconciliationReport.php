<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

/**
 * @phpstan-type TypeSummary array{count: int, exposure_by_currency: array<string, string>, exposure_usd: float}
 * @phpstan-type Summary array{
 *     internal_orders: int,
 *     settlements: array<string, int>,
 *     matched_exact: int,
 *     matched_fuzzy: int,
 *     total_discrepancies: int,
 *     total_exposure_usd: float,
 *     by_type: array<string, TypeSummary>
 * }
 * @phpstan-type Row array<string, string|int|float|null>
 */
final readonly class ReconciliationReport
{
    public const array DISCREPANCY_COLUMNS = [
        'type', 'priority', 'order_id', 'settlement_source', 'settlement_id', 'customer_id', 'chef_id', 'currency',
        'order_amount', 'settled_amount', 'impact', 'impact_usd', 'match_kind', 'note',
    ];
    public const array CHEF_PAYOUT_COLUMNS = [
        'chef_id', 'currency', 'settled_gross', 'commission', 'payable_now', 'on_hold', 'discrepancy_count',
    ];

    /**
     * @param array<string, mixed> $meta
     * @param Summary $summary
     * @param array<string, mixed> $insights
     * @param list<Row> $discrepancies
     * @param list<Row> $chefPayouts
     * @param list<string> $warnings
     */
    public function __construct(
        public array $meta,
        public array $summary,
        public array $insights,
        public array $discrepancies,
        public array $chefPayouts,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'summary' => $this->summary,
            'insights' => $this->insights,
            'chef_payouts' => $this->chefPayouts,
            'discrepancies' => $this->discrepancies,
            'warnings' => $this->warnings,
        ];
    }
}
