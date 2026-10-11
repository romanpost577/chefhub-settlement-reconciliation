<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

use App\Reconciliation\Model\Currency;
use App\Reconciliation\Model\Money;
use App\Reconciliation\Model\PaymentStatus;
use App\Reconciliation\Model\Source;
use App\Reconciliation\Model\Transaction;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * E-wallet settlement report: {"transactions": [{payment_reference, merchant.order_ref, payer_id, total, ccy,
 * timestamp (unix seconds), state}]}.
 */
final class ProcessorBJsonReader implements SourceReader
{
    private const array STATES = [
        'success' => PaymentStatus::PAID,
        'refunded' => PaymentStatus::REFUNDED,
    ];

    public function read(string $path): IngestionResult
    {
        $collector = new IngestionCollector(basename($path));
        foreach ($this->loadTransactions($path) as $index => $item) {
            $row = $index + 1;
            if (!is_array($item)) {
                $collector->warn($row, 'transaction is not an object');

                continue;
            }

            try {
                $collector->add($row, $this->map($item));
            } catch (InvalidArgumentException $exception) {
                $collector->warn($row, $exception->getMessage());
            }
        }

        return $collector->result();
    }

    /**
     * @return list<mixed>
     *
     * @throws SourceFileException
     */
    private function loadTransactions(string $path): array
    {
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new SourceFileException(sprintf('Cannot read file "%s"', $path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SourceFileException(
                sprintf('File "%s" is not valid JSON: %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }
        $transactions = is_array($data) ? ($data['transactions'] ?? null) : null;
        if (!is_array($transactions) || !array_is_list($transactions)) {
            throw new SourceFileException(sprintf('File "%s" has no "transactions" list', $path));
        }

        return $transactions;
    }

    /**
     * @param array<mixed> $item
     *
     * @throws InvalidArgumentException
     */
    private function map(array $item): Transaction
    {
        $merchant = $item['merchant'] ?? null;
        $state = self::required($item, 'state');

        return new Transaction(
            source: Source::PROCESSOR_B,
            sourceId: self::required($item, 'payment_reference'),
            orderRef: OrderRefNormalizer::normalize(
                is_array($merchant) ? self::scalar($merchant['order_ref'] ?? null) : null,
            ),
            customerId: self::required($item, 'payer_id'),
            chefId: null,
            amountMinor: Money::parse(self::required($item, 'total')),
            currency: Currency::normalize(self::required($item, 'ccy')),
            status: self::STATES[strtolower($state)]
                ?? throw new InvalidArgumentException(sprintf('unknown state "%s"', $state)),
            occurredAt: self::timestamp($item['timestamp'] ?? null),
        );
    }

    /**
     * @param array<mixed> $item
     *
     * @throws InvalidArgumentException
     */
    private static function required(array $item, string $key): string
    {
        return self::scalar($item[$key] ?? null) ?? throw new InvalidArgumentException('missing ' . $key);
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return new DateTimeImmutable('@' . $value);
        }

        throw new InvalidArgumentException('invalid timestamp');
    }
}
