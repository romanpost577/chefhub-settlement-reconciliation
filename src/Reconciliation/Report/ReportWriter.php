<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use JsonException;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ReportWriter
{
    public function __construct(
        private CsvWriter $csvWriter,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<string> paths of the written files
     *
     * @throws RuntimeException
     */
    public function write(ReconciliationReport $report, string $directory): array
    {
        try {
            $this->filesystem->mkdir($directory);
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Cannot create directory "%s"', $directory), 0, $exception);
        }
        $jsonPath = $directory . '/report.json';
        $discrepanciesPath = $directory . '/discrepancies.csv';
        $payoutsPath = $directory . '/chef_payouts.csv';

        try {
            $json = json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode report: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $this->filesystem->dumpFile($jsonPath, $json . "\n");
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Cannot write file "%s"', $jsonPath), 0, $exception);
        }
        $this->csvWriter->write($discrepanciesPath, ReconciliationReport::DISCREPANCY_COLUMNS, $report->discrepancies);
        $this->csvWriter->write($payoutsPath, ReconciliationReport::CHEF_PAYOUT_COLUMNS, $report->chefPayouts);

        return [$jsonPath, $discrepanciesPath, $payoutsPath];
    }
}
