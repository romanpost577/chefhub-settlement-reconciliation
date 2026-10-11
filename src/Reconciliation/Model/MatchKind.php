<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

enum MatchKind: string
{
    case EXACT = 'exact';
    case FUZZY = 'fuzzy';
}
