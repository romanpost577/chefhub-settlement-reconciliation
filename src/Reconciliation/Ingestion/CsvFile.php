<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use Generator;

final class CsvFile
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Yields file line number => row keyed by header, or null when the column count differs from the header.
     * Blank lines are skipped.
     *
     * @return Generator<int, array<string, string>|null>
     *
     * @throws SourceFileException
     */
    public static function rows(string $path): Generator
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new SourceFileException(sprintf('Cannot read file "%s"', $path));
        }

        try {
            $header = self::readHeader($handle, $path);
            $line = 1;
            while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($fields === [null]) {
                    continue;
                }
                $values = array_map(static fn (?string $value): string => (string)$value, $fields);

                yield $line => count($values) === count($header) ? array_combine($header, $values) : null;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     *
     * @throws SourceFileException
     */
    private static function readHeader($handle, string $path): array
    {
        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw new SourceFileException(sprintf('File "%s" has no header row', $path));
        }
        $columns = array_map(static fn (?string $column): string => trim((string)$column), $header);
        $columns[0] = str_starts_with($columns[0], self::UTF8_BOM) ? substr($columns[0], 3) : $columns[0];

        return $columns;
    }
}
