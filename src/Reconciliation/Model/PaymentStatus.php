<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

enum PaymentStatus: string
{
    case PAID = 'paid';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';
}
