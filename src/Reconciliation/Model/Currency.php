<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

use InvalidArgumentException;

final class Currency
{
    public const array SUPPORTED = ['PHP', 'IDR', 'THB'];

    /**
     * @throws InvalidArgumentException
     */
    public static function normalize(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (!in_array($normalized, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(sprintf('unsupported currency "%s"', $code));
        }

        return $normalized;
    }
}
