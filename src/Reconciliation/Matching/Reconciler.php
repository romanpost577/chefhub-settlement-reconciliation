<?php

declare(strict_types=1);

namespace App\Reconciliation\Matching;

use App\Reconciliation\Model\Discrepancy;
use App\Reconciliation\Model\DiscrepancyType;
use App\Reconciliation\Model\FxRates;
use App\Reconciliation\Model\MatchedPair;
use App\Reconciliation\Model\MatchKind;
use App\Reconciliation\Model\Money;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Priority;
use App\Reconciliation\Model\ReconciliationResult;
use App\Reconciliation\Model\Transaction;

/**
 * Pure matching logic: exact match by order ref, fuzzy fallback, then classification. O(n) via hash indexes.
 */
final readonly class Reconciler
{
    public const int AMOUNT_TOLERANCE_MINOR = 5;
    public const int FUZZY_WINDOW_SECONDS = 5;

    public function __construct(
        private FxRates $fxRates,
    ) {
    }

    /**
     * @param list<Transaction> $orders
     * @param list<Transaction> $settlements
     */
    public function reconcile(array $orders, array $settlements): ReconciliationResult
    {
        [$attached, $unattached] = $this->attachExact($orders, $settlements);
        [$attached, $phantoms, $warnings] = $this->attachFuzzy($orders, $unattached, $attached);

        $matches = [];
        $discrepancies = [];
        foreach ($orders as $order) {
            [$match, $found] = $this->classifyOrder($order, $attached[$order->sourceId] ?? []);
            if ($match !== null) {
                $matches[] = $match;
            }
            array_push($discrepancies, ...$found);
        }
        foreach ($phantoms as $settlement) {
            $discrepancies[] = $this->discrepancy(
                DiscrepancyType::MISSING_IN_DATABASE,
                null,
                $settlement,
                $settlement->amountMinor,
                $settlement->currency,
                null,
                'settled but no matching order',
            );
        }

        return new ReconciliationResult(
            count($orders),
            $this->countBySource($settlements),
            $matches,
            $discrepancies,
            $warnings,
        );
    }

    /**
     * @param list<Transaction> $orders
     * @param list<Transaction> $settlements
     *
     * @return array{array<string, list<MatchedPair>>, list<Transaction>}
     */
    private function attachExact(array $orders, array $settlements): array
    {
        $ordersById = [];
        foreach ($orders as $order) {
            $ordersById[$order->sourceId] = $order;
        }
        $attached = [];
        $unattached = [];
        foreach ($settlements as $settlement) {
            $order = $settlement->orderRef === null ? null : ($ordersById[$settlement->orderRef] ?? null);
            if ($order === null) {
                $unattached[] = $settlement;

                continue;
            }
            $attached[$order->sourceId][] = new MatchedPair($order, $settlement, MatchKind::EXACT);
        }

        return [$attached, $unattached];
    }

    /**
     * Unattached settlements may only claim orders that have no settlement yet, and only when exactly one
     * order shares currency, amount and customer within the time window.
     *
     * @param list<Transaction> $orders
     * @param list<Transaction> $unattached
     * @param array<string, list<MatchedPair>> $attached
     *
     * @return array{array<string, list<MatchedPair>>, list<Transaction>, list<string>}
     */
    private function attachFuzzy(array $orders, array $unattached, array $attached): array
    {
        $index = [];
        foreach ($orders as $order) {
            if (!isset($attached[$order->sourceId])) {
                $index[self::fuzzyKey($order)][] = $order;
            }
        }
        $phantoms = [];
        $warnings = [];
        foreach ($unattached as $settlement) {
            $candidates = array_values(array_filter(
                $index[self::fuzzyKey($settlement)] ?? [],
                static fn (Transaction $order): bool => !isset($attached[$order->sourceId])
                    && abs($order->occurredAt->getTimestamp() - $settlement->occurredAt->getTimestamp())
                        <= self::FUZZY_WINDOW_SECONDS,
            ));
            if (count($candidates) === 1) {
                $attached[$candidates[0]->sourceId][] = new MatchedPair($candidates[0], $settlement, MatchKind::FUZZY);

                continue;
            }
            if (count($candidates) > 1) {
                $warnings[] = sprintf(
                    'ambiguous fuzzy match for %s %s: %d candidate orders',
                    $settlement->source->value,
                    $settlement->sourceId,
                    count($candidates),
                );
            }
            $phantoms[] = $settlement;
        }

        return [$attached, $phantoms, $warnings];
    }

    /**
     * @param list<MatchedPair> $pairs
     *
     * @return array{MatchedPair|null, list<Discrepancy>}
     */
    private function classifyOrder(Transaction $order, array $pairs): array
    {
        if ($pairs === []) {
            if ($order->status === PaymentStatus::CANCELLED) {
                return [null, []];
            }

            return [null, [$this->discrepancy(
                DiscrepancyType::MISSING_IN_SETTLEMENT,
                $order,
                null,
                $order->amountMinor,
                $order->currency,
                null,
                'order was never settled by any processor',
            )]];
        }
        usort($pairs, static fn (MatchedPair $a, MatchedPair $b): int => [
            $a->settlement->currency !== $order->currency,
            abs($a->settlement->amountMinor - $order->amountMinor),
            $a->settlement->occurredAt,
        ] <=> [
            $b->settlement->currency !== $order->currency,
            abs($b->settlement->amountMinor - $order->amountMinor),
            $b->settlement->occurredAt,
        ]);
        $primary = $pairs[0];
        $primaryIssues = $this->comparePrimary($primary);
        $discrepancies = $primaryIssues;
        foreach (array_slice($pairs, 1) as $extra) {
            $discrepancies[] = $this->discrepancy(
                DiscrepancyType::DUPLICATE_SETTLEMENT,
                $order,
                $extra->settlement,
                $extra->settlement->amountMinor,
                $extra->settlement->currency,
                $extra->kind,
                sprintf('order settled more than once; primary %s', $primary->settlement->sourceId),
            );
        }

        return [$primaryIssues === [] ? $primary : null, $discrepancies];
    }

    /**
     * @return list<Discrepancy>
     */
    private function comparePrimary(MatchedPair $pair): array
    {
        $order = $pair->order;
        $settlement = $pair->settlement;
        $issues = [];
        if ($order->status === PaymentStatus::CANCELLED || $order->status !== $settlement->status) {
            $issues[] = $this->discrepancy(
                DiscrepancyType::STATUS_MISMATCH,
                $order,
                $settlement,
                $settlement->amountMinor,
                $settlement->currency,
                $pair->kind,
                sprintf('order is %s, processor reports %s', $order->status->value, $settlement->status->value),
            );
        }
        if ($order->currency !== $settlement->currency) {
            $issues[] = $this->discrepancy(
                DiscrepancyType::AMOUNT_MISMATCH,
                $order,
                $settlement,
                $order->amountMinor,
                $order->currency,
                $pair->kind,
                sprintf(
                    'currency differs: ordered %s %s, settled %s %s',
                    $order->currency,
                    Money::format($order->amountMinor),
                    $settlement->currency,
                    Money::format($settlement->amountMinor),
                ),
            );
        } elseif (abs($settlement->amountMinor - $order->amountMinor) > self::AMOUNT_TOLERANCE_MINOR) {
            $issues[] = $this->discrepancy(
                DiscrepancyType::AMOUNT_MISMATCH,
                $order,
                $settlement,
                abs($settlement->amountMinor - $order->amountMinor),
                $order->currency,
                $pair->kind,
                sprintf(
                    'settled %s vs ordered %s',
                    Money::format($settlement->amountMinor),
                    Money::format($order->amountMinor),
                ),
            );
        }

        return $issues;
    }

    /**
     * The currency is the one the impact amount is expressed in (order or settlement side).
     */
    private function discrepancy(
        DiscrepancyType $type,
        ?Transaction $order,
        ?Transaction $settlement,
        int $impactMinor,
        string $currency,
        ?MatchKind $kind,
        string $note,
    ): Discrepancy {
        $impactUsd = $this->fxRates->toUsd($impactMinor, $currency);

        return new Discrepancy(
            $type,
            $order,
            $settlement,
            $impactMinor,
            $currency,
            $impactUsd,
            Priority::fromUsd($impactUsd),
            $kind,
            $note,
        );
    }

    /**
     * @param list<Transaction> $settlements
     *
     * @return array<string, int>
     */
    private function countBySource(array $settlements): array
    {
        $counts = [];
        foreach ($settlements as $settlement) {
            $counts[$settlement->source->value] = ($counts[$settlement->source->value] ?? 0) + 1;
        }

        return $counts;
    }

    private static function fuzzyKey(Transaction $transaction): string
    {
        return $transaction->currency . '|' . $transaction->amountMinor . '|' . $transaction->customerId;
    }
}
