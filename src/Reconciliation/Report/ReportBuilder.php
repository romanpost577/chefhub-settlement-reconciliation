<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use App\Reconciliation\Model\Discrepancy;
use App\Reconciliation\Model\DiscrepancyType;
use App\Reconciliation\Model\MatchKind;
use App\Reconciliation\Model\Money;
use App\Reconciliation\Model\Priority;
use App\Reconciliation\Model\ReconciliationResult;
use App\Reconciliation\Model\Source;

/**
 * @phpstan-import-type Summary from ReconciliationReport
 * @phpstan-import-type Row from ReconciliationReport
 */
final class ReportBuilder
{
    public const int TOP_DISCREPANCIES = 20;

    /**
     * @param list<ChefPayout> $chefPayouts
     * @param list<string> $ingestionWarnings
     * @param array<string, mixed> $meta
     */
    public function build(
        ReconciliationResult $result,
        array $chefPayouts,
        array $ingestionWarnings,
        array $meta,
    ): ReconciliationReport {
        $sorted = $result->discrepancies;
        usort($sorted, static fn (Discrepancy $a, Discrepancy $b): int => $b->impactUsd <=> $a->impactUsd);
        $rows = array_map(self::discrepancyRow(...), $sorted);

        return new ReconciliationReport(
            $meta,
            $this->summary($result),
            $this->insights($sorted, $rows),
            $rows,
            array_map(self::payoutRow(...), $chefPayouts),
            [...$ingestionWarnings, ...$result->warnings],
        );
    }

    /**
     * @return Summary
     */
    private function summary(ReconciliationResult $result): array
    {
        $byType = [];
        foreach (DiscrepancyType::cases() as $type) {
            $byType[$type->value] = [
                'count' => 0,
                'exposure_by_currency' => [],
                'exposure_usd' => 0.0,
            ];
        }
        $minorByType = [];
        $totalUsd = 0.0;
        foreach ($result->discrepancies as $discrepancy) {
            $type = $discrepancy->type->value;
            $byType[$type]['count']++;
            $byType[$type]['exposure_usd'] = round($byType[$type]['exposure_usd'] + $discrepancy->impactUsd, 2);
            $minorByType[$type][$discrepancy->currency] =
                ($minorByType[$type][$discrepancy->currency] ?? 0) + $discrepancy->impactMinor;
            $totalUsd += $discrepancy->impactUsd;
        }
        foreach ($minorByType as $type => $perCurrency) {
            ksort($perCurrency);
            $byType[$type]['exposure_by_currency'] = array_map(Money::format(...), $perCurrency);
        }

        return [
            'internal_orders' => $result->orderCount,
            'settlements' => $result->settlementCounts,
            'matched_exact' => $result->matchedCount(MatchKind::EXACT),
            'matched_fuzzy' => $result->matchedCount(MatchKind::FUZZY),
            'total_discrepancies' => count($result->discrepancies),
            'total_exposure_usd' => round($totalUsd, 2),
            'by_type' => $byType,
        ];
    }

    /**
     * @param list<Discrepancy> $sorted
     * @param list<Row> $rows
     *
     * @return array<string, mixed>
     */
    private function insights(array $sorted, array $rows): array
    {
        $bySource = [];
        $byPriority = array_fill_keys(array_map(static fn (Priority $p): string => $p->value, Priority::cases()), 0);
        foreach ($sorted as $discrepancy) {
            $type = $discrepancy->type->value;
            $source = $discrepancy->settlement !== null
                ? $discrepancy->settlement->source->value
                : Source::INTERNAL->value;
            $cell = $bySource[$type][$source] ?? [
                'count' => 0,
                'exposure_usd' => 0.0,
            ];
            $bySource[$type][$source] = [
                'count' => $cell['count'] + 1,
                'exposure_usd' => round($cell['exposure_usd'] + $discrepancy->impactUsd, 2),
            ];
            $byPriority[$discrepancy->priority->value]++;
        }

        return [
            'top_discrepancies' => array_slice($rows, 0, self::TOP_DISCREPANCIES),
            'by_type_and_source' => $bySource,
            'by_priority' => $byPriority,
        ];
    }

    /**
     * @return Row
     */
    private static function discrepancyRow(Discrepancy $discrepancy): array
    {
        $order = $discrepancy->order;
        $settlement = $discrepancy->settlement;

        return [
            'type' => $discrepancy->type->value,
            'priority' => $discrepancy->priority->value,
            'order_id' => $order?->sourceId,
            'settlement_source' => $settlement?->source->value,
            'settlement_id' => $settlement?->sourceId,
            'customer_id' => $order !== null ? $order->customerId : $settlement?->customerId,
            'chef_id' => $order?->chefId,
            'currency' => $discrepancy->currency,
            'order_amount' => $order !== null ? Money::format($order->amountMinor) : null,
            'settled_amount' => $settlement !== null ? Money::format($settlement->amountMinor) : null,
            'impact' => Money::format($discrepancy->impactMinor),
            'impact_usd' => $discrepancy->impactUsd,
            'match_kind' => $discrepancy->matchKind?->value,
            'note' => $discrepancy->note,
        ];
    }

    /**
     * @return Row
     */
    private static function payoutRow(ChefPayout $payout): array
    {
        return [
            'chef_id' => $payout->chefId,
            'currency' => $payout->currency,
            'settled_gross' => Money::format($payout->settledGrossMinor),
            'commission' => Money::format($payout->commissionMinor),
            'payable_now' => Money::format($payout->payableNowMinor),
            'on_hold' => Money::format($payout->onHoldMinor),
            'discrepancy_count' => $payout->discrepancyCount,
        ];
    }
}
