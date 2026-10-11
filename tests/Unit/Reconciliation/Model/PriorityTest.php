<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Model;

use App\Reconciliation\Model\Priority;
use PHPUnit\Framework\TestCase;

final class PriorityTest extends TestCase
{
    public function testFromUsdUsesThresholds(): void
    {
        self::assertSame(Priority::HIGH, Priority::fromUsd(100.0));
        self::assertSame(Priority::MEDIUM, Priority::fromUsd(99.99));
        self::assertSame(Priority::MEDIUM, Priority::fromUsd(10.0));
        self::assertSame(Priority::LOW, Priority::fromUsd(9.99));
        self::assertSame(Priority::LOW, Priority::fromUsd(0.0));
    }
}
