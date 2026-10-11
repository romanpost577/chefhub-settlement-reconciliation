<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use RuntimeException;

final class CsvWriter
{
    /**
     * Cells starting with these characters are evaluated as formulas by spreadsheet apps.
     */
    private const string FORMULA_PATTERN = '/^[=+\-@\t\r]/';

    /**
     * @param list<string> $columns
     * @param iterable<array<string, string|int|float|null>> $rows
     *
     * @throws RuntimeException
     */
    public function write(string $path, array $columns, iterable $rows): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot write file "%s"', $path));
        }

        try {
            fputcsv($handle, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv(
                    $handle,
                    array_map(static fn (string $column): string => self::cell($row[$column] ?? null), $columns),
                    ',',
                    '"',
                    '',
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Source IDs come from external files; prefix text that a spreadsheet would run as a formula.
     */
    private static function cell(string|int|float|null $value): string
    {
        $text = (string)$value;

        return !is_numeric($text) && preg_match(self::FORMULA_PATTERN, $text) === 1 ? "'" . $text : $text;
    }
}
