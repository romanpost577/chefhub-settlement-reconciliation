<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Model;

use App\Reconciliation\Model\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testNormalizeUppercasesAndTrims(): void
    {
        self::assertSame('PHP', Currency::normalize(' php '));
        self::assertSame('IDR', Currency::normalize('IDR'));
    }

    public function testNormalizeRejectsUnsupportedCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('unsupported currency "USD"');

        Currency::normalize('USD');
    }
}
