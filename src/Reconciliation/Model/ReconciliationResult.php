<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

final readonly class ReconciliationResult
{
    /**
     * @param array<string, int> $settlementCounts settlements per Source value
     * @param list<MatchedPair> $matches orders whose primary settlement agrees on amount and status
     * @param list<Discrepancy> $discrepancies
     * @param list<string> $warnings
     */
    public function __construct(
        public int $orderCount,
        public array $settlementCounts,
        public array $matches,
        public array $discrepancies,
        public array $warnings,
    ) {
    }

    public function matchedCount(MatchKind $kind): int
    {
        return count(array_filter($this->matches, static fn (MatchedPair $pair): bool => $pair->kind === $kind));
    }
}
