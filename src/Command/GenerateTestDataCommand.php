<?php

declare(strict_types=1);

namespace App\Command;

use App\Reconciliation\TestData\DatasetGenerator;
use App\Reconciliation\TestData\DatasetWriter;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-test-data',
    description: 'Generates deterministic internal orders, Processor A and Processor B files plus a manifest.',
)]
final class GenerateTestDataCommand extends Command
{
    public function __construct(
        private readonly DatasetGenerator $generator,
        private readonly DatasetWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('orders', null, InputOption::VALUE_REQUIRED, 'Number of internal orders', '1000')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed', '42')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory', 'data/sample');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orders = filter_var($input->getOption('orders'), FILTER_VALIDATE_INT);
        $seed = filter_var($input->getOption('seed'), FILTER_VALIDATE_INT);
        $out = $input->getOption('out');
        if ($orders === false || $seed === false || !is_string($out) || $out === '') {
            $io->error('--orders and --seed must be integers, --out a directory.');

            return Command::INVALID;
        }

        try {
            $dataset = $this->generator->generate($orders, $seed);
            $this->writer->write($dataset, $out);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($dataset->manifest['expected'] as $key => $count) {
            $rows[] = [$key, $count];
        }
        $io->table(['Seeded', 'Count'], $rows);
        $io->success(sprintf(
            'Wrote %d orders, %d Processor A rows, %d Processor B rows to %s',
            count($dataset->internalOrders),
            count($dataset->processorA),
            count($dataset->processorB),
            $out,
        ));

        return Command::SUCCESS;
    }
}
