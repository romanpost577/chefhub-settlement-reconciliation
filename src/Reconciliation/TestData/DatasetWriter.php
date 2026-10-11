<?php

declare(strict_types=1);

namespace App\Reconciliation\TestData;

use App\Reconciliation\Report\CsvWriter;
use JsonException;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class DatasetWriter
{
    public const string INTERNAL_FILE = 'internal_orders.csv';
    public const string PROCESSOR_A_FILE = 'processor_a_settlement.csv';
    public const string PROCESSOR_B_FILE = 'processor_b_settlement.json';
    public const string MANIFEST_FILE = 'manifest.json';

    private const array INTERNAL_COLUMNS = [
        'order_id', 'customer_id', 'chef_id', 'total_amount', 'currency', 'status', 'payment_method', 'paid_at',
    ];
    private const array PROCESSOR_A_COLUMNS = [
        'txn_id', 'merchant_order_ref', 'customer_ref', 'amount_minor', 'currency_code', 'txn_time',
        'settlement_date', 'status', 'fee_minor',
    ];

    public function __construct(
        private CsvWriter $csvWriter,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function write(GeneratedDataset $dataset, string $directory): void
    {
        try {
            $this->filesystem->mkdir($directory);
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Cannot create directory "%s"', $directory), 0, $exception);
        }
        $this->csvWriter->write(
            $directory . '/' . self::INTERNAL_FILE,
            self::INTERNAL_COLUMNS,
            $dataset->internalOrders,
        );
        $this->csvWriter->write(
            $directory . '/' . self::PROCESSOR_A_FILE,
            self::PROCESSOR_A_COLUMNS,
            $dataset->processorA,
        );
        $this->writeJson($directory . '/' . self::PROCESSOR_B_FILE, [
            'provider' => 'GoWallet',
            'report_period' => [
                'from' => '2026-10-01',
                'to' => '2026-10-07',
            ],
            'transactions' => $dataset->processorB,
        ]);
        $this->writeJson($directory . '/' . self::MANIFEST_FILE, $dataset->manifest);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException
     */
    private function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode ' . $path, 0, $exception);
        }

        try {
            $this->filesystem->dumpFile($path, $json . "\n");
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Cannot write file "%s"', $path), 0, $exception);
        }
    }
}
