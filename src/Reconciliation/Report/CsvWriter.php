<?php

declare(strict_types=1);

namespace App\Reconciliation\Report;

use RuntimeException;
use SplFileObject;

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
        try {
            $file = new SplFileObject($path, 'wb');
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf('Cannot write file "%s"', $path), 0, $exception);
        }
        $file->fputcsv($columns, ',', '"', '');
        foreach ($rows as $row) {
            $file->fputcsv(
                array_map(static fn (string $column): string => self::cell($row[$column] ?? null), $columns),
                ',',
                '"',
                '',
            );
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
