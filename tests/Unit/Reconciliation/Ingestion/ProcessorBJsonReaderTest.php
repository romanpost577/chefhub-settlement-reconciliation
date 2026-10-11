<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Ingestion;

use App\Reconciliation\Ingestion\ProcessorBJsonReader;
use App\Reconciliation\Ingestion\SourceFileException;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use PHPUnit\Framework\TestCase;

final class ProcessorBJsonReaderTest extends TestCase
{
    use TempFiles;

    public function testMapsTransactionsToNormalizedTransactions(): void
    {
        $path = $this->json([
            [
                'payment_reference' => 'GW-1',
                'merchant' => [
                    'order_ref' => 'ch123',
                ],
                'payer_id' => 'CU-00042',
                'total' => '1234.5',
                'ccy' => 'php',
                'timestamp' => 1790906400,
                'state' => 'success',
            ],
            [
                'payment_reference' => 'GW-2',
                'merchant' => [
                    'order_ref' => null,
                ],
                'payer_id' => 'CU-1',
                'total' => 99,
                'ccy' => 'IDR',
                'timestamp' => '1790906401',
                'state' => 'refunded',
            ],
        ]);

        $result = new ProcessorBJsonReader()->read($path);

        self::assertSame([], $result->warnings);
        [$first, $second] = $result->transactions;
        self::assertSame(Source::PROCESSOR_B, $first->source);
        self::assertSame('GW-1', $first->sourceId);
        self::assertSame('CH-000123', $first->orderRef);
        self::assertSame(123450, $first->amountMinor);
        self::assertSame('PHP', $first->currency);
        self::assertSame(PaymentStatus::PAID, $first->status);
        self::assertSame('2026-10-02 02:00:00', $first->occurredAt->format('Y-m-d H:i:s'));
        self::assertNull($second->orderRef);
        self::assertSame(9900, $second->amountMinor);
        self::assertSame(PaymentStatus::REFUNDED, $second->status);
    }

    public function testSkipsMalformedAndDuplicateTransactions(): void
    {
        $valid = [
            'payment_reference' => 'GW-1',
            'merchant' => [
                'order_ref' => 'CH-000001',
            ],
            'payer_id' => 'CU-1',
            'total' => '10.00',
            'ccy' => 'THB',
            'timestamp' => 1790906400,
            'state' => 'success',
        ];
        $path = $this->json([
            $valid,
            'not an object',
            [
                ...$valid,
                'payment_reference' => 'GW-2',
                'timestamp' => 'yesterday',
            ],
            [
                ...$valid,
                'payment_reference' => 'GW-3',
                'state' => 'pending',
            ],
            [
                ...$valid,
                'payment_reference' => null,
            ],
            $valid,
        ]);

        $result = new ProcessorBJsonReader()->read($path);

        self::assertCount(1, $result->transactions);
        self::assertSame([
            'settlement.json row 2: transaction is not an object',
            'settlement.json row 3: invalid timestamp',
            'settlement.json row 4: unknown state "pending"',
            'settlement.json row 5: missing payment_reference',
            'settlement.json row 6: duplicate row GW-1',
        ], $result->warnings);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(SourceFileException::class);
        $this->expectExceptionMessageIsOrContains('is not valid JSON');

        new ProcessorBJsonReader()->read($this->tempFile('{"transactions": [', 'settlement.json'));
    }

    public function testRejectsJsonWithoutTransactionsList(): void
    {
        $this->expectException(SourceFileException::class);
        $this->expectExceptionMessageIsOrContains('has no "transactions" list');

        new ProcessorBJsonReader()->read($this->tempFile('{"items": []}', 'settlement.json'));
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(SourceFileException::class);

        new ProcessorBJsonReader()->read('/nonexistent/settlement.json');
    }

    /**
     * @param list<mixed> $transactions
     */
    private function json(array $transactions): string
    {
        return $this->tempFile(
            json_encode([
                'provider' => 'GoWallet',
                'transactions' => $transactions,
            ], JSON_THROW_ON_ERROR),
            'settlement.json',
        );
    }
}
