<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use App\Reconciliation\Model\FxRates;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\ReconciliationResult;
use App\Reconciliation\Model\Transaction;

/**
 * Payable now: orders paid on both sides with no discrepancy, minus commission.
 * On hold: amounts of orders that have at least one discrepancy.
 *
 * @phpstan-type Line array{chef: string, currency: string, gross: int, hold: int, count: int}
 */
final readonly class ChefPayoutCalculator
{
    public const int COMMISSION_PERCENT = 15;

    public function __construct(
        private FxRates $fxRates,
    ) {
    }

    /**
     * @return list<ChefPayout>
     */
    public function calculate(ReconciliationResult $result): array
    {
        /** @var array<string, Line> $lines */
        $lines = [];
        $flagged = [];
        foreach ($result->discrepancies as $discrepancy) {
            $order = $discrepancy->order;
            if ($order === null || $order->chefId === null) {
                continue;
            }
            $line = $lines[self::key($order)] ?? self::emptyLine($order);
            $line['count']++;
            if (!isset($flagged[$order->sourceId])) {
                $line['hold'] += $order->amountMinor;
                $flagged[$order->sourceId] = true;
            }
            $lines[self::key($order)] = $line;
        }
        foreach ($result->matches as $match) {
            $order = $match->order;
            if ($order->status !== PaymentStatus::PAID || isset($flagged[$order->sourceId])) {
                continue;
            }
            $line = $lines[self::key($order)] ?? self::emptyLine($order);
            $line['gross'] += $order->amountMinor;
            $lines[self::key($order)] = $line;
        }

        return $this->toPayouts(array_values($lines));
    }

    /**
     * @param list<Line> $lines
     *
     * @return list<ChefPayout> sorted by amount on hold in USD (comparable across currencies), then chef ID
     */
    private function toPayouts(array $lines): array
    {
        $payouts = array_map(static function (array $line): ChefPayout {
            $commission = intdiv($line['gross'] * self::COMMISSION_PERCENT + 50, 100);

            return new ChefPayout(
                $line['chef'],
                $line['currency'],
                $line['gross'],
                $commission,
                $line['gross'] - $commission,
                $line['hold'],
                $line['count'],
            );
        }, $lines);
        usort(
            $payouts,
            fn (ChefPayout $a, ChefPayout $b): int => [$this->onHoldUsd($b), $a->chefId] <=> [$this->onHoldUsd($a), $b->chefId],
        );

        return $payouts;
    }

    private function onHoldUsd(ChefPayout $payout): float
    {
        return $this->fxRates->toUsd($payout->onHoldMinor, $payout->currency);
    }

    private static function key(Transaction $order): string
    {
        return $order->chefId . '|' . $order->currency;
    }

    /**
     * @return Line
     */
    private static function emptyLine(Transaction $order): array
    {
        return [
            'chef' => (string)$order->chefId,
            'currency' => $order->currency,
            'gross' => 0,
            'hold' => 0,
            'count' => 0,
        ];
    }
}
