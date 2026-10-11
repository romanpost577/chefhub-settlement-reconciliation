<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

final readonly class Discrepancy
{
    public function __construct(
        public DiscrepancyType $type,
        public ?Transaction $order,
        public ?Transaction $settlement,
        public int $impactMinor,
        public string $currency,
        public float $impactUsd,
        public Priority $priority,
        public ?MatchKind $matchKind,
        public string $note,
    ) {
    }
}
