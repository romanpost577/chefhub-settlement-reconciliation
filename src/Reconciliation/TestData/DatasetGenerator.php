<?php

declare(strict_types=1);

namespace App\Reconciliation\TestData;

use App\Reconciliation\Model\Currency;
use App\Reconciliation\Model\DiscrepancyType;
use App\Reconciliation\Model\Money;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic generator of the three source files plus the counts it seeded.
 * Payment times are at least 6 s apart, so fuzzy matching (5 s window) is never ambiguous.
 */
final class DatasetGenerator
{
    private const string WEEK_START = '2026-10-01T00:00:00Z';
    private const int WEEK_SECONDS = 604_800;
    private const int DAY_SECONDS = 86_400;
    private const int MIN_GAP_SECONDS = 6;
    private const int SCENARIO_SCALE = 10_000;
    /**
     * Cumulative thresholds out of SCENARIO_SCALE; a roll above the last one is a clean order.
     */
    private const array SCENARIOS = [
        500 => 'missing',
        700 => 'amount',
        800 => 'status',
        900 => 'duplicate',
        1_100 => 'cancelled',
        1_150 => 'rounding',
    ];
    private const int PROCESSOR_A_SHARE_PERCENT = 60;
    private const int CLEAN_REFUND_PERCENT = 3;
    private const int GARBLED_REF_PERCENT = 2;
    private const int NULL_REF_PERCENT = 2;
    private const int PHANTOM_PER_MILLE = 30;
    private const int FEE_PER_MILLE = 29;
    private const array AMOUNT_RANGES = [
        'PHP' => [15_000, 350_000],
        'IDR' => [2_500_000, 60_000_000],
        'THB' => [8_000, 200_000],
    ];
    private const array TIMEZONES = [
        'PHP' => '+08:00',
        'IDR' => '+07:00',
        'THB' => '+07:00',
    ];

    private Randomizer $random;

    /**
     * @var list<array<string, string>>
     */
    private array $internal = [];

    /**
     * @var list<array<string, string>>
     */
    private array $processorA = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $processorB = [];

    /**
     * @var array<string, int>
     */
    private array $expected = [];

    private int $txnCounter = 0;

    public function __construct()
    {
        $this->random = new Randomizer(new Mt19937(0));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function generate(int $orderCount, int $seed): GeneratedDataset
    {
        $maxOrders = intdiv(self::WEEK_SECONDS, self::MIN_GAP_SECONDS);
        if ($orderCount < 1 || $orderCount > $maxOrders) {
            throw new InvalidArgumentException(sprintf('orders must be between 1 and %d', $maxOrders));
        }
        $this->reset($seed);
        $start = new DateTimeImmutable(self::WEEK_START)->getTimestamp();
        $step = intdiv(self::WEEK_SECONDS, $orderCount);
        $chefs = max(10, intdiv($orderCount, 100));
        $customers = max(50, intdiv($orderCount, 5));
        for ($i = 1; $i <= $orderCount; $i++) {
            $this->addOrder($this->newOrder($i, $start + ($i - 1) * $step, $step, $chefs, $customers));
        }
        $this->addPhantoms(intdiv($orderCount * self::PHANTOM_PER_MILLE, 1000), $start);
        $this->addRepeatedRows(max(1, intdiv($orderCount, 1000)));

        /** @var list<array<string, string>> $processorA */
        $processorA = array_values($this->random->shuffleArray($this->processorA));
        /** @var list<array<string, mixed>> $processorB */
        $processorB = array_values($this->random->shuffleArray($this->processorB));

        return new GeneratedDataset(
            $this->internal,
            $processorA,
            $processorB,
            [
                'seed' => $seed,
                'orders' => $orderCount,
                'expected' => $this->expected,
            ],
        );
    }

    private function reset(int $seed): void
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->internal = [];
        $this->processorA = [];
        $this->processorB = [];
        $this->txnCounter = 0;
        $this->expected = [
            'matched_exact' => 0,
            'matched_fuzzy' => 0,
            'duplicate_rows' => 0,
        ];
        foreach (DiscrepancyType::cases() as $type) {
            $this->expected[$type->value] = 0;
        }
    }

    private function newOrder(int $number, int $slotStart, int $step, int $chefs, int $customers): GeneratedOrder
    {
        $chef = $this->random->getInt(1, $chefs);
        $currency = Currency::SUPPORTED[$chef % count(Currency::SUPPORTED)];
        [$min, $max] = self::AMOUNT_RANGES[$currency];

        return new GeneratedOrder(
            sprintf('CH-%06d', $number),
            sprintf('CU-%05d', $this->random->getInt(1, $customers)),
            sprintf('CF-%04d', $chef),
            $currency,
            $this->random->getInt($min, $max),
            $slotStart + $this->random->getInt(0, $step - self::MIN_GAP_SECONDS),
        );
    }

    private function addOrder(GeneratedOrder $order): void
    {
        $viaA = $this->percent(self::PROCESSOR_A_SHARE_PERCENT);
        match ($this->pickScenario()) {
            'missing' => $this->missing($order, $viaA),
            'amount' => $this->amountMismatch($order, $viaA),
            'status' => $this->statusMismatch($order, $viaA),
            'duplicate' => $this->duplicate($order, $viaA),
            'cancelled' => $this->writeInternal($order, 'cancelled', $viaA),
            'rounding' => $this->rounding($order, $viaA),
            default => $this->clean($order, $viaA),
        };
    }

    private function pickScenario(): string
    {
        $roll = $this->random->getInt(0, self::SCENARIO_SCALE - 1);
        foreach (self::SCENARIOS as $threshold => $scenario) {
            if ($roll < $threshold) {
                return $scenario;
            }
        }

        return 'clean';
    }

    private function clean(GeneratedOrder $order, bool $viaA): void
    {
        $refunded = $this->percent(self::CLEAN_REFUND_PERCENT);
        $this->writeInternal($order, $refunded ? 'refunded' : 'completed', $viaA);
        $ref = $order->id;
        $roll = $this->random->getInt(1, 100);
        if (!$viaA && $roll <= self::GARBLED_REF_PERCENT) {
            $ref = 'ch' . (int)substr($order->id, 3);
        } elseif (!$viaA && $roll <= self::GARBLED_REF_PERCENT + self::NULL_REF_PERCENT) {
            $ref = null;
        }
        $this->settle($viaA, $ref, $order, $order->amountMinor, $order->paidAt, $refunded);
        $this->expected[$ref === null ? 'matched_fuzzy' : 'matched_exact']++;
    }

    private function rounding(GeneratedOrder $order, bool $viaA): void
    {
        $this->writeInternal($order, 'completed', $viaA);
        $delta = $this->random->getInt(1, 5) * ($this->random->getInt(0, 1) === 1 ? 1 : -1);
        $this->settle($viaA, $order->id, $order, $order->amountMinor + $delta, $order->paidAt, false);
        $this->expected['matched_exact']++;
    }

    private function missing(GeneratedOrder $order, bool $viaA): void
    {
        $this->writeInternal($order, 'completed', $viaA);
        $this->expected[DiscrepancyType::MISSING_IN_SETTLEMENT->value]++;
    }

    private function amountMismatch(GeneratedOrder $order, bool $viaA): void
    {
        $this->writeInternal($order, 'completed', $viaA);
        $delta = $this->random->getInt(6, 45_000);
        $settled = $order->amountMinor - $delta > 0 && $this->random->getInt(0, 1) === 1
            ? $order->amountMinor - $delta
            : $order->amountMinor + $delta;
        $this->settle($viaA, $order->id, $order, $settled, $order->paidAt, false);
        $this->expected[DiscrepancyType::AMOUNT_MISMATCH->value]++;
    }

    /**
     * Variants: refund not synced to the processor, refund not synced to ChefHub, cancelled order that settled.
     */
    private function statusMismatch(GeneratedOrder $order, bool $viaA): void
    {
        $variant = $this->random->getInt(1, 3);
        $internalStatus = match ($variant) {
            1 => 'refunded',
            2 => 'completed',
            default => 'cancelled',
        };
        $this->writeInternal($order, $internalStatus, $viaA);
        $this->settle($viaA, $order->id, $order, $order->amountMinor, $order->paidAt, $variant === 2);
        $this->expected[DiscrepancyType::STATUS_MISMATCH->value]++;
    }

    private function duplicate(GeneratedOrder $order, bool $viaA): void
    {
        $this->writeInternal($order, 'completed', $viaA);
        $this->settle($viaA, $order->id, $order, $order->amountMinor, $order->paidAt, false);
        $secondTime = $order->paidAt + $this->random->getInt(60, 3_600);
        $this->settle($this->random->getInt(0, 1) === 1, $order->id, $order, $order->amountMinor, $secondTime, false);
        $this->expected['matched_exact']++;
        $this->expected[DiscrepancyType::DUPLICATE_SETTLEMENT->value]++;
    }

    /**
     * Phantoms use refs CH-9xxxxx (beyond any generated order) and customers CU-Pxxxx (absent from orders),
     * so neither exact nor fuzzy matching can attach them.
     */
    private function addPhantoms(int $count, int $start): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $currency = Currency::SUPPORTED[$this->random->getInt(0, count(Currency::SUPPORTED) - 1)];
            [$min, $max] = self::AMOUNT_RANGES[$currency];
            $viaA = $this->percent(self::PROCESSOR_A_SHARE_PERCENT);
            $ref = $viaA || $this->random->getInt(0, 1) === 1 ? sprintf('CH-9%05d', $i) : null;
            $paidAt = $start + $this->random->getInt(0, self::WEEK_SECONDS - 1);
            $amount = $this->random->getInt($min, $max);
            $phantom = new GeneratedOrder('', sprintf('CU-P%04d', $i), '', $currency, $amount, $paidAt);
            $this->settle($viaA, $ref, $phantom, $phantom->amountMinor, $paidAt, false);
        }
        $this->expected[DiscrepancyType::MISSING_IN_DATABASE->value] = $count;
    }

    private function addRepeatedRows(int $count): void
    {
        if ($this->processorA === []) {
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $this->processorA[] = $this->processorA[$this->random->getInt(0, count($this->processorA) - 1)];
        }
        $this->expected['duplicate_rows'] = $count;
    }

    private function writeInternal(GeneratedOrder $order, string $status, bool $viaA): void
    {
        $paidAt = new DateTimeImmutable('@' . $order->paidAt)
            ->setTimezone(new DateTimeZone(self::TIMEZONES[$order->currency]));
        $this->internal[] = [
            'order_id' => $order->id,
            'customer_id' => $order->customerId,
            'chef_id' => $order->chefId,
            'total_amount' => Money::format($order->amountMinor),
            'currency' => $order->currency,
            'status' => $status,
            'payment_method' => $viaA ? 'card' : 'ewallet',
            'paid_at' => $paidAt->format(DateTimeInterface::ATOM),
        ];
    }

    private function settle(
        bool $viaA,
        ?string $ref,
        GeneratedOrder $order,
        int $amountMinor,
        int $paidAt,
        bool $refunded,
    ): void {
        $this->txnCounter++;
        if ($viaA) {
            $this->processorA[] = [
                'txn_id' => sprintf('PA-%08d', $this->txnCounter),
                'merchant_order_ref' => $ref ?? '',
                'customer_ref' => $order->customerId,
                'amount_minor' => (string)$amountMinor,
                'currency_code' => $order->currency,
                'txn_time' => gmdate('d/m/Y H:i:s', $paidAt),
                'settlement_date' => gmdate('Y-m-d', $paidAt + self::DAY_SECONDS * $this->random->getInt(1, 3)),
                'status' => $refunded ? 'REFUNDED' : 'SETTLED',
                'fee_minor' => (string)intdiv($amountMinor * self::FEE_PER_MILLE, 1000),
            ];

            return;
        }
        $this->processorB[] = [
            'payment_reference' => sprintf('GW-%08d', $this->txnCounter),
            'merchant' => [
                'order_ref' => $ref,
            ],
            'payer_id' => $order->customerId,
            'total' => Money::format($amountMinor),
            'ccy' => $order->currency,
            'timestamp' => $paidAt,
            'state' => $refunded ? 'refunded' : 'success',
        ];
    }

    private function percent(int $chance): bool
    {
        return $this->random->getInt(1, 100) <= $chance;
    }
}
