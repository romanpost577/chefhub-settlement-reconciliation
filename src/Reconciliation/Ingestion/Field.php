<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Field
{
    /**
     * @param array<string, string> $row
     *
     * @throws InvalidArgumentException
     */
    public static function required(array $row, string $key): string
    {
        return self::optional($row, $key) ?? throw new InvalidArgumentException('missing ' . $key);
    }

    /**
     * @param array<string, string> $row
     */
    public static function optional(array $row, string $key): ?string
    {
        $value = trim($row[$key] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Parses strictly (the value must round-trip through the format) and returns the time in UTC.
     * A trailing "Z" UTC designator is accepted as "+00:00".
     *
     * @throws InvalidArgumentException
     */
    public static function date(string $value, string $format, string $key): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = DateTimeImmutable::createFromFormat('!' . $format, $normalized, $utc);
        if ($date === false || $date->format($format) !== $normalized) {
            throw new InvalidArgumentException(sprintf('invalid %s "%s"', $key, $value));
        }

        return $date->setTimezone($utc);
    }
}
