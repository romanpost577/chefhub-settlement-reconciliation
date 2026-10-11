<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation;

use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Model\Transaction;
use DateTimeImmutable;

trait TransactionFactory
{
    private static function order(
        string $id,
        int $amountMinor,
        PaymentStatus $status = PaymentStatus::PAID,
        string $customerId = 'CU-1',
        string $time = '2026-10-02 10:00:00',
        string $chefId = 'CF-1',
        string $currency = 'PHP',
    ): Transaction {
        return new Transaction(
            Source::INTERNAL,
            $id,
            $id,
            $customerId,
            $chefId,
            $amountMinor,
            $currency,
            $status,
            new DateTimeImmutable($time . ' UTC'),
        );
    }

    private static function settlement(
        string $id,
        ?string $orderRef,
        int $amountMinor,
        PaymentStatus $status = PaymentStatus::PAID,
        string $customerId = 'CU-1',
        string $time = '2026-10-02 10:00:00',
        Source $source = Source::PROCESSOR_A,
        string $currency = 'PHP',
    ): Transaction {
        return new Transaction(
            $source,
            $id,
            $orderRef,
            $customerId,
            null,
            $amountMinor,
            $currency,
            $status,
            new DateTimeImmutable($time . ' UTC'),
        );
    }
}
