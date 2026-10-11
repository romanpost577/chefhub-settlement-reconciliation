<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use JsonException;
use RuntimeException;

final readonly class ReportWriter
{
    public function __construct(
        private CsvWriter $csvWriter,
    ) {
    }

    /**
     * @return list<string> paths of the written files
     *
     * @throws RuntimeException
     */
    public function write(ReconciliationReport $report, string $directory): array
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s"', $directory));
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
        if (file_put_contents($jsonPath, $json . "\n") === false) {
            throw new RuntimeException(sprintf('Cannot write file "%s"', $jsonPath));
        }
        $this->csvWriter->write($discrepanciesPath, ReconciliationReport::DISCREPANCY_COLUMNS, $report->discrepancies);
        $this->csvWriter->write($payoutsPath, ReconciliationReport::CHEF_PAYOUT_COLUMNS, $report->chefPayouts);

        return [$jsonPath, $discrepanciesPath, $payoutsPath];
    }
}
