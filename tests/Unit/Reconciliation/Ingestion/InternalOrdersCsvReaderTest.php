<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Ingestion;

use App\Reconciliation\Ingestion\InternalOrdersCsvReader;
use App\Reconciliation\Ingestion\SourceFileException;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use PHPUnit\Framework\TestCase;

final class InternalOrdersCsvReaderTest extends TestCase
{
    use TempFiles;

    private const string HEADER = "order_id,customer_id,chef_id,total_amount,currency,status,payment_method,paid_at\n";

    public function testMapsRowToNormalizedTransaction(): void
    {
        $path = $this->tempFile(
            self::HEADER . "CH-000123,CU-00042,CF-0007,1234.50,php,completed,card,2026-10-03T14:22:05+08:00\n",
        );

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->transactions);
        $order = $result->transactions[0];
        self::assertSame(Source::INTERNAL, $order->source);
        self::assertSame('CH-000123', $order->sourceId);
        self::assertSame('CH-000123', $order->orderRef);
        self::assertSame('CU-00042', $order->customerId);
        self::assertSame('CF-0007', $order->chefId);
        self::assertSame(123450, $order->amountMinor);
        self::assertSame('PHP', $order->currency);
        self::assertSame(PaymentStatus::PAID, $order->status);
        self::assertSame('2026-10-03 06:22:05 +00:00', $order->occurredAt->format('Y-m-d H:i:s P'));
    }

    public function testAcceptsUtcDesignatorZ(): void
    {
        $path = $this->tempFile(self::HEADER . "CH-000001,CU-1,CF-1,10.00,PHP,completed,card,2026-10-03T06:22:05Z\n");

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertSame([], $result->warnings);
        self::assertSame('2026-10-03 06:22:05 +00:00', $result->transactions[0]->occurredAt->format('Y-m-d H:i:s P'));
    }

    public function testMapsAllStatuses(): void
    {
        $path = $this->tempFile(
            self::HEADER
            . "CH-000001,CU-1,CF-1,10.00,PHP,refunded,card,2026-10-03T14:22:05+08:00\n"
            . "CH-000002,CU-1,CF-1,10.00,PHP,CANCELLED,card,2026-10-03T14:22:06+08:00\n",
        );

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertSame(PaymentStatus::REFUNDED, $result->transactions[0]->status);
        self::assertSame(PaymentStatus::CANCELLED, $result->transactions[1]->status);
    }

    public function testSkipsMalformedRowsWithWarnings(): void
    {
        $path = $this->tempFile(
            self::HEADER
            . "CH-000001,CU-1,CF-1,10.00,PHP,completed,card,03/10/2026\n"
            . "CH-000002,CU-1,CF-1,10.00,PHP,lost,card,2026-10-03T14:22:05+08:00\n"
            . "CH-000003,CU-1,CF-1,10.00,USD,completed,card,2026-10-03T14:22:05+08:00\n"
            . "CH-000004,CU-1,CF-1\n"
            . "CH-000005,CU-1,CF-1,ten,PHP,completed,card,2026-10-03T14:22:05+08:00\n"
            . "CH-000006,,CF-1,10.00,PHP,completed,card,2026-10-03T14:22:05+08:00\n",
        );

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertSame([], $result->transactions);
        self::assertSame([
            'source.csv row 2: invalid paid_at "03/10/2026"',
            'source.csv row 3: unknown status "lost"',
            'source.csv row 4: unsupported currency "USD"',
            'source.csv row 5: column count does not match header',
            'source.csv row 6: invalid amount "ten"',
            'source.csv row 7: missing customer_id',
        ], $result->warnings);
    }

    public function testSkipsDuplicateRowsWithWarning(): void
    {
        $row = "CH-000001,CU-1,CF-1,10.00,PHP,completed,card,2026-10-03T14:22:05+08:00\n";
        $path = $this->tempFile(self::HEADER . $row . $row);

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertCount(1, $result->transactions);
        self::assertSame(['source.csv row 3: duplicate row CH-000001'], $result->warnings);
    }

    public function testHandlesBomCrlfAndBlankLines(): void
    {
        $path = $this->tempFile(
            "\xEF\xBB\xBF" . str_replace("\n", "\r\n", self::HEADER)
            . "CH-000001,CU-1,CF-1,10.00,PHP,completed,card,2026-10-03T14:22:05+08:00\r\n\r\n",
        );

        $result = new InternalOrdersCsvReader()->read($path);

        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->transactions);
    }

    public function testHeaderOnlyFileGivesNoTransactions(): void
    {
        $result = new InternalOrdersCsvReader()->read($this->tempFile(self::HEADER));

        self::assertSame([], $result->transactions);
        self::assertSame([], $result->warnings);
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectException(SourceFileException::class);
        $this->expectExceptionMessageIsOrContains('has no header row');

        new InternalOrdersCsvReader()->read($this->tempFile(''));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(SourceFileException::class);
        $this->expectExceptionMessageIsOrContains('Cannot read file');

        new InternalOrdersCsvReader()->read('/nonexistent/internal_orders.csv');
    }
}
