<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

enum DiscrepancyType: string
{
    case MISSING_IN_SETTLEMENT = 'missing_in_settlement';
    case MISSING_IN_DATABASE = 'missing_in_database';
    case AMOUNT_MISMATCH = 'amount_mismatch';
    case STATUS_MISMATCH = 'status_mismatch';
    case DUPLICATE_SETTLEMENT = 'duplicate_settlement';
}
