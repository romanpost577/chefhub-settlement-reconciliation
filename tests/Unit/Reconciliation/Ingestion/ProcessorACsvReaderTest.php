<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Ingestion;

use App\Reconciliation\Ingestion\ProcessorACsvReader;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use PHPUnit\Framework\TestCase;

final class ProcessorACsvReaderTest extends TestCase
{
    use TempFiles;

    private const string HEADER =
        "txn_id,merchant_order_ref,customer_ref,amount_minor,currency_code,txn_time,settlement_date,status,fee_minor\n";

    public function testMapsRowToNormalizedTransaction(): void
    {
        $path = $this->tempFile(
            self::HEADER . "PA-00000001,ch123,CU-00042,123450,PHP,03/10/2026 06:22:05,2026-10-05,SETTLED,3580\n",
        );

        $result = new ProcessorACsvReader()->read($path);

        self::assertSame([], $result->warnings);
        $settlement = $result->transactions[0];
        self::assertSame(Source::PROCESSOR_A, $settlement->source);
        self::assertSame('PA-00000001', $settlement->sourceId);
        self::assertSame('CH-000123', $settlement->orderRef);
        self::assertSame('CU-00042', $settlement->customerId);
        self::assertNull($settlement->chefId);
        self::assertSame(123450, $settlement->amountMinor);
        self::assertSame(PaymentStatus::PAID, $settlement->status);
        self::assertSame('2026-10-03 06:22:05 +00:00', $settlement->occurredAt->format('Y-m-d H:i:s P'));
        self::assertSame('2026-10-05', $settlement->settledOn?->format('Y-m-d'));
    }

    public function testRefundedStatusAndMissingRef(): void
    {
        $path = $this->tempFile(
            self::HEADER . "PA-00000002,,CU-1,500,THB,03/10/2026 06:22:05,,refunded,15\n",
        );

        $settlement = new ProcessorACsvReader()->read($path)->transactions[0];

        self::assertSame(PaymentStatus::REFUNDED, $settlement->status);
        self::assertNull($settlement->orderRef);
        self::assertNull($settlement->settledOn);
    }

    public function testSkipsMalformedAndDuplicateRows(): void
    {
        $path = $this->tempFile(
            self::HEADER
            . "PA-1,CH-000001,CU-1,12.50,PHP,03/10/2026 06:22:05,2026-10-05,SETTLED,1\n"
            . "PA-2,CH-000002,CU-1,1250,PHP,31/02/2026 06:22:05,2026-10-05,SETTLED,1\n"
            . "PA-3,CH-000003,CU-1,1250,PHP,03/10/2026 06:22:05,2026-10-05,PENDING,1\n"
            . "PA-4,CH-000004,CU-1,1250,PHP,03/10/2026 06:22:05,2026-10-05,SETTLED,1\n"
            . "PA-4,CH-000004,CU-1,1250,PHP,03/10/2026 06:22:05,2026-10-05,SETTLED,1\n",
        );

        $result = new ProcessorACsvReader()->read($path);

        self::assertCount(1, $result->transactions);
        self::assertSame([
            'source.csv row 2: invalid amount_minor "12.50"',
            'source.csv row 3: invalid txn_time "31/02/2026 06:22:05"',
            'source.csv row 4: unknown status "PENDING"',
            'source.csv row 6: duplicate row PA-4',
        ], $result->warnings);
    }
}
