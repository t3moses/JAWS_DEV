<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Enum;

use App\Domain\Enum\CrewRankDimension;
use PHPUnit\Framework\TestCase;

class CrewRankDimensionTest extends TestCase
{
    public function testEnumValuesAreCorrect(): void
    {
        // Arrange
        // Assert
        $this->assertEquals(0, CrewRankDimension::AVAILABILITY->value);
        $this->assertEquals(1, CrewRankDimension::COMMITMENT->value);
        $this->assertEquals(2, CrewRankDimension::MEMBERSHIP->value);
        $this->assertEquals(3, CrewRankDimension::ABSENCE->value);
    }

    public function testAllReturnsAllDimensionsInOrder(): void
    {
        // Arrange
        $dimensions = CrewRankDimension::all();

        // Assert
        $this->assertCount(4, $dimensions);
        $this->assertEquals(CrewRankDimension::AVAILABILITY, $dimensions[0]);
        $this->assertEquals(CrewRankDimension::COMMITMENT, $dimensions[1]);
        $this->assertEquals(CrewRankDimension::MEMBERSHIP, $dimensions[2]);
        $this->assertEquals(CrewRankDimension::ABSENCE, $dimensions[3]);
    }

    public function testAllEnumCasesExist(): void
    {
        // Arrange
        $cases = CrewRankDimension::cases();

        // Assert
        $this->assertCount(4, $cases);
        $this->assertContains(CrewRankDimension::AVAILABILITY, $cases);
        $this->assertContains(CrewRankDimension::COMMITMENT, $cases);
        $this->assertContains(CrewRankDimension::MEMBERSHIP, $cases);
        $this->assertContains(CrewRankDimension::ABSENCE, $cases);
    }

    public function testEnumFromInt(): void
    {
        // Arrange
        // Assert
        $this->assertEquals(CrewRankDimension::AVAILABILITY, CrewRankDimension::from(0));
        $this->assertEquals(CrewRankDimension::COMMITMENT, CrewRankDimension::from(1));
        $this->assertEquals(CrewRankDimension::MEMBERSHIP, CrewRankDimension::from(2));
        $this->assertEquals(CrewRankDimension::ABSENCE, CrewRankDimension::from(3));
    }

    public function testEnumFromIntThrowsExceptionForInvalidValue(): void
    {
        // Arrange
        // Assert
        $this->expectException(\ValueError::class);

        CrewRankDimension::from(99);
    }
}
