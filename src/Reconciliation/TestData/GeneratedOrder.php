<?php

declare(strict_types=1);

namespace App\Reconciliation\TestData;

final readonly class GeneratedOrder
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $chefId,
        public string $currency,
        public int $amountMinor,
        public int $paidAt,
    ) {
    }
}
