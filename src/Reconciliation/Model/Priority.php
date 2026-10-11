<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

enum Priority: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public const float HIGH_THRESHOLD_USD = 100.0;
    public const float MEDIUM_THRESHOLD_USD = 10.0;

    public static function fromUsd(float $usd): self
    {
        return match (true) {
            $usd >= self::HIGH_THRESHOLD_USD => self::HIGH,
            $usd >= self::MEDIUM_THRESHOLD_USD => self::MEDIUM,
            default => self::LOW,
        };
    }
}
