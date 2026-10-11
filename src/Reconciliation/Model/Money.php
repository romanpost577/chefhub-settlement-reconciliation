<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

use InvalidArgumentException;

/**
 * Converts between decimal strings and integer minor units (2 decimals) without floats.
 */
final class Money
{
    private const string DECIMAL_PATTERN = '/^\s*(\d+)(?:\.(\d{1,2}))?\s*$/';

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $decimal): int
    {
        if (preg_match(self::DECIMAL_PATTERN, $decimal, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('invalid amount "%s"', $decimal));
        }

        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    public static function format(int $minor): string
    {
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }
}
