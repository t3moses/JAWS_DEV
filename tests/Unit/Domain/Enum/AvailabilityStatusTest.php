<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Enum;

use App\Domain\Enum\AvailabilityStatus;
use PHPUnit\Framework\TestCase;

class AvailabilityStatusTest extends TestCase
{
    public function testEnumValuesAreCorrect(): void
    {
        $this->assertEquals(0, AvailabilityStatus::NOT_SELECTED->value);
        $this->assertEquals(1, AvailabilityStatus::SELECTED->value);
    }

    public function testIsSelectedReturnsTrueForSelected(): void
    {
        $this->assertTrue(AvailabilityStatus::SELECTED->isSelected());
    }

    public function testIsSelectedReturnsFalseForNotSelected(): void
    {
        $this->assertFalse(AvailabilityStatus::NOT_SELECTED->isSelected());
    }

    public function testEnumFromInt(): void
    {
        $this->assertEquals(AvailabilityStatus::NOT_SELECTED, AvailabilityStatus::from(0));
        $this->assertEquals(AvailabilityStatus::SELECTED, AvailabilityStatus::from(1));
    }

    public function testEnumFromIntThrowsExceptionForInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        AvailabilityStatus::from(99);
    }

    public function testAllEnumCasesExist(): void
    {
        $cases = AvailabilityStatus::cases();
        $this->assertCount(2, $cases);
        $this->assertContains(AvailabilityStatus::NOT_SELECTED, $cases);
        $this->assertContains(AvailabilityStatus::SELECTED, $cases);
    }
}
