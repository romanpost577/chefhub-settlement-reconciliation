<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Transaction;

/**
 * Accumulates the rows of one source file: keeps the first occurrence of each source ID, records warnings.
 */
final class IngestionCollector
{
    /**
     * @var list<Transaction>
     */
    private array $transactions = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @var array<string, true>
     */
    private array $seenIds = [];

    public function __construct(
        private readonly string $fileName,
    ) {
    }

    public function add(int $row, Transaction $transaction): void
    {
        if (isset($this->seenIds[$transaction->sourceId])) {
            $this->warn($row, 'duplicate row ' . $transaction->sourceId);

            return;
        }
        $this->seenIds[$transaction->sourceId] = true;
        $this->transactions[] = $transaction;
    }

    public function warn(int $row, string $reason): void
    {
        $this->warnings[] = sprintf('%s row %d: %s', $this->fileName, $row, $reason);
    }

    public function result(): IngestionResult
    {
        return new IngestionResult($this->transactions, $this->warnings);
    }
}
