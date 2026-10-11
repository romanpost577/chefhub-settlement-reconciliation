<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Transaction;
use InvalidArgumentException;

abstract class CsvSourceReader implements SourceReader
{
    public function read(string $path): IngestionResult
    {
        $collector = new IngestionCollector(basename($path));
        foreach (CsvFile::rows($path) as $line => $row) {
            if ($row === null) {
                $collector->warn($line, 'column count does not match header');

                continue;
            }

            try {
                $collector->add($line, $this->map($row));
            } catch (InvalidArgumentException $exception) {
                $collector->warn($line, $exception->getMessage());
            }
        }

        return $collector->result();
    }

    /**
     * @param array<string, string> $row
     *
     * @throws InvalidArgumentException
     */
    abstract protected function map(array $row): Transaction;
}
