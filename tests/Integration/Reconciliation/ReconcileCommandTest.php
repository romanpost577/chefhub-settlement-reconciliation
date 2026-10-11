<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reconciliation;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileCommandTest extends KernelTestCase
{
    use RemovesDirectories;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/reconciliation';

    private string $outDir;

    protected function setUp(): void
    {
        $this->outDir = sys_get_temp_dir() . '/reconcile-command-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->outDir);
        parent::tearDown();
    }

    public function testReconcilesFixturesAndWritesReports(): void
    {
        $tester = $this->runCommand([
            '--internal' => self::FIXTURES . '/internal_orders.csv',
            '--processor-a' => self::FIXTURES . '/processor_a_settlement.csv',
            '--processor-b' => self::FIXTURES . '/processor_b_settlement.json',
            '--out' => $this->outDir,
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->outDir . '/latest/discrepancies.csv');
        self::assertFileExists($this->outDir . '/latest/chef_payouts.csv');
        /**
         * @var array{
         *     summary: array{
         *         internal_orders: int,
         *         settlements: array<string, int>,
         *         matched_exact: int,
         *         matched_fuzzy: int,
         *         by_type: array<string, array{count: int}>
         *     },
         *     warnings: list<string>
         * } $report
         */
        $report = self::readJson($this->outDir . '/latest/report.json');
        $summary = $report['summary'];
        self::assertSame(8, $summary['internal_orders']);
        self::assertSame([
            'processor_a' => 4,
            'processor_b' => 4,
        ], $summary['settlements']);
        self::assertSame(3, $summary['matched_exact']);
        self::assertSame(1, $summary['matched_fuzzy']);
        self::assertSame([
            'missing_in_settlement' => 1,
            'missing_in_database' => 1,
            'amount_mismatch' => 1,
            'status_mismatch' => 1,
            'duplicate_settlement' => 1,
        ], array_map(static fn (array $type): int => $type['count'], $summary['by_type']));
        self::assertSame(['processor_a_settlement.csv row 5: duplicate row PA-00000003'], $report['warnings']);
        self::assertStringContainsString('Matched (fuzzy)', $tester->getDisplay());
        self::assertStringContainsString('duplicate_settlement', $tester->getDisplay());
    }

    public function testFailsOnMissingInputFile(): void
    {
        $tester = $this->runCommand([
            '--internal' => '/nonexistent/internal_orders.csv',
            '--processor-a' => self::FIXTURES . '/processor_a_settlement.csv',
            '--processor-b' => self::FIXTURES . '/processor_b_settlement.json',
            '--out' => $this->outDir,
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cannot read file', $tester->getDisplay());
    }

    public function testFailsOnUnwritableOutputDirectory(): void
    {
        $tester = $this->runCommand([
            '--internal' => self::FIXTURES . '/internal_orders.csv',
            '--processor-a' => self::FIXTURES . '/processor_a_settlement.csv',
            '--processor-b' => self::FIXTURES . '/processor_b_settlement.json',
            '--out' => '/proc/forbidden',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cannot create directory', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:reconcile'));
        $tester->execute($options);

        return $tester;
    }
}
