<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

use DateTimeImmutable;

/**
 * One record from any source, normalized: order IDs canonical, amounts in minor units, times in UTC.
 */
final readonly class Transaction
{
    public function __construct(
        public Source $source,
        public string $sourceId,
        public ?string $orderRef,
        public string $customerId,
        public ?string $chefId,
        public int $amountMinor,
        public string $currency,
        public PaymentStatus $status,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $settledOn = null,
    ) {
    }
}
