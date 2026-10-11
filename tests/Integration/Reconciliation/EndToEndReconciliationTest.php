<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reconciliation;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The proof of correctness: the engine finds exactly the discrepancies the generator seeded.
 */
final class EndToEndReconciliationTest extends KernelTestCase
{
    use RemovesDirectories;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/reconcile-e2e-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
        parent::tearDown();
    }

    public function testFoundDiscrepanciesEqualSeededOnes(): void
    {
        $application = new Application(self::bootKernel());
        $data = $this->workDir . '/data';
        $generate = new CommandTester($application->find('app:generate-test-data'));
        $generate->execute([
            '--orders' => '3000',
            '--seed' => '7',
            '--out' => $data,
        ]);
        self::assertSame(0, $generate->getStatusCode(), $generate->getDisplay());

        $reconcile = new CommandTester($application->find('app:reconcile'));
        $reconcile->execute([
            '--internal' => $data . '/internal_orders.csv',
            '--processor-a' => $data . '/processor_a_settlement.csv',
            '--processor-b' => $data . '/processor_b_settlement.json',
            '--out' => $this->workDir . '/reports',
        ]);
        self::assertSame(0, $reconcile->getStatusCode(), $reconcile->getDisplay());

        /** @var array{expected: array<string, int>} $manifest */
        $manifest = self::readJson($data . '/manifest.json');
        /**
         * @var array{
         *     summary: array{matched_exact: int, matched_fuzzy: int, by_type: array<string, array{count: int}>},
         *     warnings: list<string>
         * } $report
         */
        $report = self::readJson($this->workDir . '/reports/latest/report.json');
        $expected = $manifest['expected'];
        $summary = $report['summary'];
        self::assertSame($expected['matched_exact'], $summary['matched_exact']);
        self::assertSame($expected['matched_fuzzy'], $summary['matched_fuzzy']);
        foreach ($summary['by_type'] as $type => $typeSummary) {
            self::assertSame($expected[$type], $typeSummary['count'], $type);
        }
        self::assertCount($expected['duplicate_rows'], $report['warnings']);
    }
}
