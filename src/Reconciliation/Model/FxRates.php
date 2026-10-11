<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

use InvalidArgumentException;

/**
 * Indicative, fixed USD rates so that reports are reproducible and exposures are comparable across currencies.
 */
final class FxRates
{
    private const array USD_PER_UNIT = [
        'PHP' => 0.0172,
        'IDR' => 0.000061,
        'THB' => 0.0285,
    ];

    /**
     * @throws InvalidArgumentException
     */
    public function toUsd(int $minor, string $currency): float
    {
        $rate = self::USD_PER_UNIT[$currency] ?? throw new InvalidArgumentException(
            sprintf('no FX rate for "%s"', $currency),
        );

        return round($minor / 100 * $rate, 2);
    }
}
