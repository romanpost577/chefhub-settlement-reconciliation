<?php

declare(strict_types=1);

namespace App\Command;

use App\Reconciliation\Ingestion\InternalOrdersCsvReader;
use App\Reconciliation\Ingestion\ProcessorACsvReader;
use App\Reconciliation\Ingestion\ProcessorBJsonReader;
use App\Reconciliation\Ingestion\SourceFileException;
use App\Reconciliation\Matching\Reconciler;
use App\Reconciliation\Report\ChefPayoutCalculator;
use App\Reconciliation\Report\ReconciliationReport;
use App\Reconciliation\Report\ReportBuilder;
use App\Reconciliation\Report\ReportWriter;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reconcile',
    description: 'Reconciles ChefHub orders against Processor A and Processor B settlement reports.',
)]
final class ReconcileCommand extends Command
{
    private const int BYTES_IN_MB = 1_048_576;
    private const int NANOSECONDS_IN_MS = 1_000_000;
    private const string DEFAULT_INTERNAL = 'data/sample/internal_orders.csv';
    private const string DEFAULT_A = 'data/sample/processor_a_settlement.csv';
    private const string DEFAULT_B = 'data/sample/processor_b_settlement.json';

    public function __construct(
        private readonly InternalOrdersCsvReader $internalReader,
        private readonly ProcessorACsvReader $processorAReader,
        private readonly ProcessorBJsonReader $processorBReader,
        private readonly Reconciler $reconciler,
        private readonly ChefPayoutCalculator $chefPayoutCalculator,
        private readonly ReportBuilder $reportBuilder,
        private readonly ReportWriter $reportWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('internal', null, InputOption::VALUE_REQUIRED, 'Internal orders CSV', self::DEFAULT_INTERNAL)
            ->addOption('processor-a', null, InputOption::VALUE_REQUIRED, 'Processor A report (CSV)', self::DEFAULT_A)
            ->addOption('processor-b', null, InputOption::VALUE_REQUIRED, 'Processor B report (JSON)', self::DEFAULT_B)
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory', 'var/reports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startedAt = hrtime(true);
        $paths = [
            'internal' => self::option($input, 'internal'),
            'processor_a' => self::option($input, 'processor-a'),
            'processor_b' => self::option($input, 'processor-b'),
        ];

        try {
            $internal = $this->internalReader->read($paths['internal']);
            $processorA = $this->processorAReader->read($paths['processor_a']);
            $processorB = $this->processorBReader->read($paths['processor_b']);
        } catch (SourceFileException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $result = $this->reconciler->reconcile(
            $internal->transactions,
            [...$processorA->transactions, ...$processorB->transactions],
        );
        $report = $this->reportBuilder->build(
            $result,
            $this->chefPayoutCalculator->calculate($result),
            [...$internal->warnings, ...$processorA->warnings, ...$processorB->warnings],
            [
                'generated_at' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
                'inputs' => $paths,
                'duration_ms' => intdiv(hrtime(true) - $startedAt, self::NANOSECONDS_IN_MS),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / self::BYTES_IN_MB, 1),
            ],
        );

        $outDir = rtrim(self::option($input, 'out'), '/');

        try {
            $this->reportWriter->write($report, $outDir . '/' . date('Ymd-His'));
            $this->reportWriter->write($report, $outDir . '/latest');
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $this->printSummary($io, $report, $outDir . '/latest');

        return Command::SUCCESS;
    }

    private function printSummary(SymfonyStyle $io, ReconciliationReport $report, string $latestDir): void
    {
        $summary = $report->summary;
        $duration = $report->meta['duration_ms'] ?? null;
        $io->title('ChefHub settlement reconciliation');
        $io->definitionList(
            [
                'Internal orders' => (string)$summary['internal_orders'],
            ],
            [
                'Settlements' => (string)array_sum($summary['settlements']),
            ],
            [
                'Matched (exact)' => (string)$summary['matched_exact'],
            ],
            [
                'Matched (fuzzy)' => (string)$summary['matched_fuzzy'],
            ],
            [
                'Warnings' => (string)count($report->warnings),
            ],
            [
                'Duration' => (is_int($duration) ? (string)$duration : '?') . ' ms',
            ],
        );
        $rows = [];
        foreach ($summary['by_type'] as $type => $typeSummary) {
            $rows[] = [$type, $typeSummary['count'], number_format($typeSummary['exposure_usd'], 2)];
        }
        $rows[] = ['TOTAL', $summary['total_discrepancies'], number_format($summary['total_exposure_usd'], 2)];
        $io->table(['Discrepancy type', 'Count', 'Exposure (USD)'], $rows);
        $io->success(sprintf('Report written to %s', $latestDir));
    }

    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || $value === '') {
            throw new InvalidOptionException(sprintf('Option --%s must be a non-empty string.', $name));
        }

        return $value;
    }
}
