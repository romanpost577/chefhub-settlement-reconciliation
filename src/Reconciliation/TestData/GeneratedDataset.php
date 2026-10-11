<?php

declare(strict_types=1);

namespace App\Reconciliation\TestData;

final readonly class GeneratedDataset
{
    /**
     * @param list<array<string, string>> $internalOrders
     * @param list<array<string, string>> $processorA
     * @param list<array<string, mixed>> $processorB
     * @param array{seed: int, orders: int, expected: array<string, int>} $manifest
     */
    public function __construct(
        public array $internalOrders,
        public array $processorA,
        public array $processorB,
        public array $manifest,
    ) {
    }
}
