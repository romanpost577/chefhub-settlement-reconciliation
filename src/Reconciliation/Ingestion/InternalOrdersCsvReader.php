<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Currency;
use App\Reconciliation\Model\Money;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Model\Transaction;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * ChefHub orders export: order_id,customer_id,chef_id,total_amount,currency,status,payment_method,paid_at.
 */
final class InternalOrdersCsvReader extends CsvSourceReader
{
    private const array STATUSES = [
        'completed' => PaymentStatus::PAID,
        'refunded' => PaymentStatus::REFUNDED,
        'cancelled' => PaymentStatus::CANCELLED,
    ];

    protected function map(array $row): Transaction
    {
        $orderId = Field::required($row, 'order_id');
        $orderRef = OrderRefNormalizer::normalize($orderId)
            ?? throw new InvalidArgumentException(sprintf('invalid order_id "%s"', $orderId));
        $status = Field::required($row, 'status');

        return new Transaction(
            source: Source::INTERNAL,
            sourceId: $orderRef,
            orderRef: $orderRef,
            customerId: Field::required($row, 'customer_id'),
            chefId: Field::required($row, 'chef_id'),
            amountMinor: Money::parse(Field::required($row, 'total_amount')),
            currency: Currency::normalize(Field::required($row, 'currency')),
            status: self::STATUSES[strtolower($status)]
                ?? throw new InvalidArgumentException(sprintf('unknown status "%s"', $status)),
            occurredAt: Field::date(Field::required($row, 'paid_at'), DateTimeInterface::ATOM, 'paid_at'),
        );
    }
}
