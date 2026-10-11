<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

final readonly class MatchedPair
{
    public function __construct(
        public Transaction $order,
        public Transaction $settlement,
        public MatchKind $kind,
    ) {
    }
}
