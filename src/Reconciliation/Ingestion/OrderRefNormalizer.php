<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

/**
 * Brings order references from any source to the canonical "CH-000123" form.
 */
final class OrderRefNormalizer
{
    private const string PATTERN = '/^\s*CH-?0*(\d{1,6})\s*$/i';

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || preg_match(self::PATTERN, $raw, $matches) !== 1) {
            return null;
        }

        return sprintf('CH-%06d', (int)$matches[1]);
    }
}
