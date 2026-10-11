<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

final readonly class ChefPayout
{
    public function __construct(
        public string $chefId,
        public string $currency,
        public int $settledGrossMinor,
        public int $commissionMinor,
        public int $payableNowMinor,
        public int $onHoldMinor,
        public int $discrepancyCount,
    ) {
    }
}
