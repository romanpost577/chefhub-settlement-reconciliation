<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Report;

use App\Reconciliation\Report\CsvWriter;
use App\Reconciliation\Report\ReconciliationReport;
use App\Reconciliation\Report\ReportWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/report-writer-' . bin2hex(random_bytes(6)) . '/nested';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
            rmdir(dirname($this->directory));
        }
    }

    public function testWritesJsonAndCsvFiles(): void
    {
        $report = new ReconciliationReport(
            [
                'generated_at' => 'now',
            ],
            self::summary(1.72),
            [],
            [array_fill_keys(ReconciliationReport::DISCREPANCY_COLUMNS, 'x')],
            [],
            [],
        );

        $files = new ReportWriter(new CsvWriter())->write($report, $this->directory);

        self::assertSame([
            $this->directory . '/report.json',
            $this->directory . '/discrepancies.csv',
            $this->directory . '/chef_payouts.csv',
        ], $files);
        $json = json_decode((string)file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['summary']);
        self::assertSame(1.72, $json['summary']['total_exposure_usd']);
        $csv = (array)file($files[1], FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $csv);
        self::assertSame(implode(',', ReconciliationReport::DISCREPANCY_COLUMNS), $csv[0]);
        self::assertSame(
            [implode(',', ReconciliationReport::CHEF_PAYOUT_COLUMNS)],
            file($files[2], FILE_IGNORE_NEW_LINES),
        );
    }

    public function testNeutralizesSpreadsheetFormulas(): void
    {
        mkdir($this->directory, 0775, true);
        $path = $this->directory . '/rows.csv';

        new CsvWriter()->write($path, ['id', 'amount'], [
            [
                'id' => '=HYPERLINK("http://evil")',
                'amount' => '-10.00',
            ],
            [
                'id' => '@SUM(A1)',
                'amount' => '5.00',
            ],
        ]);

        self::assertSame(
            ['id,amount', '"\'=HYPERLINK(""http://evil"")",-10.00', "'@SUM(A1),5.00"],
            file($path, FILE_IGNORE_NEW_LINES),
        );
    }

    public function testFailsWhenDirectoryCannotBeCreated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Cannot create directory');

        new ReportWriter(new CsvWriter())->write(
            new ReconciliationReport([], self::summary(0.0), [], [], [], []),
            '/proc/forbidden/reports',
        );
    }

    /**
     * @return array{internal_orders: int, settlements: array<string, int>, matched_exact: int, matched_fuzzy: int,
     *     total_discrepancies: int, total_exposure_usd: float, by_type: array<string, array{count: int,
     *     exposure_by_currency: array<string, string>, exposure_usd: float}>}
     */
    private static function summary(float $exposureUsd): array
    {
        return [
            'internal_orders' => 0,
            'settlements' => [],
            'matched_exact' => 0,
            'matched_fuzzy' => 0,
            'total_discrepancies' => 0,
            'total_exposure_usd' => $exposureUsd,
            'by_type' => [],
        ];
    }
}
