<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Transaction;

final readonly class IngestionResult
{
    /**
     * @param list<Transaction> $transactions
     * @param list<string> $warnings
     */
    public function __construct(
        public array $transactions,
        public array $warnings,
    ) {
    }
}
