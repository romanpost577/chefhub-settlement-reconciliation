<?php

declare(strict_types=1);

namespace App\Reconciliation\Model;

enum Source: string
{
    case INTERNAL = 'internal';
    case PROCESSOR_A = 'processor_a';
    case PROCESSOR_B = 'processor_b';
}
