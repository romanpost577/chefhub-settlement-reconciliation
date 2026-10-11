<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Currency;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Model\Transaction;
use InvalidArgumentException;

/**
 * Card processor settlement report:
 * txn_id,merchant_order_ref,customer_ref,amount_minor,currency_code,txn_time,settlement_date,status,fee_minor.
 * Amounts in cents, txn_time "d/m/Y H:i:s" in UTC.
 */
final class ProcessorACsvReader extends CsvSourceReader
{
    private const array STATUSES = [
        'SETTLED' => PaymentStatus::PAID,
        'REFUNDED' => PaymentStatus::REFUNDED,
    ];

    protected function map(array $row): Transaction
    {
        $amount = Field::required($row, 'amount_minor');
        if (!ctype_digit($amount)) {
            throw new InvalidArgumentException(sprintf('invalid amount_minor "%s"', $amount));
        }
        $status = Field::required($row, 'status');
        $settlementDate = Field::optional($row, 'settlement_date');

        return new Transaction(
            source: Source::PROCESSOR_A,
            sourceId: Field::required($row, 'txn_id'),
            orderRef: OrderRefNormalizer::normalize(Field::optional($row, 'merchant_order_ref')),
            customerId: Field::required($row, 'customer_ref'),
            chefId: null,
            amountMinor: (int)$amount,
            currency: Currency::normalize(Field::required($row, 'currency_code')),
            status: self::STATUSES[strtoupper($status)]
                ?? throw new InvalidArgumentException(sprintf('unknown status "%s"', $status)),
            occurredAt: Field::date(Field::required($row, 'txn_time'), 'd/m/Y H:i:s', 'txn_time'),
            settledOn: $settlementDate === null ? null : Field::date($settlementDate, 'Y-m-d', 'settlement_date'),
        );
    }
}
