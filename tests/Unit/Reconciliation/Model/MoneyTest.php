<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Model;

use App\Reconciliation\Model\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validAmounts(): iterable
    {
        yield 'two decimals' => ['1234.50', 123450];
        yield 'one decimal' => ['1234.5', 123450];
        yield 'no decimals' => ['1234', 123400];
        yield 'surrounding spaces' => [' 7.05 ', 705];
        yield 'zero' => ['0.00', 0];
    }

    #[DataProvider('validAmounts')]
    public function testParseConvertsDecimalStringToMinorUnits(string $decimal, int $expected): void
    {
        self::assertSame($expected, Money::parse($decimal));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'three decimals' => ['1.234'];
        yield 'negative' => ['-5.00'];
        yield 'text' => ['abc'];
        yield 'comma separator' => ['1,50'];
    }

    #[DataProvider('invalidAmounts')]
    public function testParseRejectsInvalidAmounts(string $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($decimal);
    }

    public function testFormatRendersTwoDecimals(): void
    {
        self::assertSame('1234.50', Money::format(123450));
        self::assertSame('0.05', Money::format(5));
        self::assertSame('-10.00', Money::format(-1000));
    }
}
