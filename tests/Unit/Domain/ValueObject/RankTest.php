<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObject;

use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;
use App\Domain\Enum\CrewRankDimension;
use PHPUnit\Framework\TestCase;

class RankTest extends TestCase
{
    public function testForBoatCreatesValidBoatRank(): void
    {
        // Arrange
        $rank = Rank::forBoat(flexibility: 1, absence: 2);

        // Assert
        $this->assertEquals(1, $rank->getDimension(BoatRankDimension::FLEXIBILITY));
        $this->assertEquals(2, $rank->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testForCrewCreatesValidCrewRank(): void
    {
        // Arrange
        $rank = Rank::forCrew(
            availability: 0,
            commitment: 0,
            membership: 0,
            absence: 3
        );

        // Assert
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::AVAILABILITY));
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::COMMITMENT));
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::MEMBERSHIP));
        $this->assertEquals(3, $rank->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testFromArrayCreatesValidRank(): void
    {
        // Arrange
        $rank = Rank::fromArray([0, 1, 2, 3]);

        // Assert
        $this->assertEquals([0, 1, 2, 3], $rank->toArray());
    }

    public function testGetDimensionReturnsZeroForMissingDimension(): void
    {
        // Arrange
        $rank = Rank::forBoat(flexibility: 1, absence: 2);

        // Trying to get a dimension beyond the array bounds returns 0
        // Boat ranks have indices 0 and 1, crew ABSENCE is index 2 which doesn't exist in boat rank
        // Assert
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testToArrayReturnsAllValues(): void
    {
        // Arrange
        $rank = Rank::forBoat(flexibility: 1, absence: 2);

        $expected = [
            BoatRankDimension::FLEXIBILITY->value => 1,
            BoatRankDimension::ABSENCE->value => 2,
        ];

        // Assert
        $this->assertEquals($expected, $rank->toArray());
    }

    public function testWithDimensionCreatesNewRank(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = $rank1->withDimension(BoatRankDimension::ABSENCE, 5);

        // Original should be unchanged
        // Assert
        $this->assertEquals(2, $rank1->getDimension(BoatRankDimension::ABSENCE));

        // New rank should have updated value
        $this->assertEquals(5, $rank2->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testCompareToReturnsNegativeWhenLessThan(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 0, absence: 1);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 0);

        // rank1 < rank2 because first dimension (0) < (1)
        // Assert
        $this->assertLessThan(0, $rank1->compareTo($rank2));
    }

    public function testCompareToReturnsPositiveWhenGreaterThan(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 0);
        $rank2 = Rank::forBoat(flexibility: 0, absence: 1);

        // rank1 > rank2 because first dimension (1) > (0)
        // Assert
        $this->assertGreaterThan(0, $rank1->compareTo($rank2));
    }

    public function testCompareToReturnsZeroWhenEqual(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 2);

        // Assert
        $this->assertEquals(0, $rank1->compareTo($rank2));
    }

    public function testCompareToUsesLexicographicOrder(): void
    {
        // First dimension determines comparison if different
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 0, absence: 5);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 0);

        // rank1 < rank2 even though absence is higher, because flexibility takes precedence
        // Assert
        $this->assertLessThan(0, $rank1->compareTo($rank2));
    }

    public function testCompareToChecksSecondDimensionWhenFirstIsEqual(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 5);

        // First dimension equal, so second dimension determines order
        // Assert
        $this->assertLessThan(0, $rank1->compareTo($rank2));
    }

    public function testIsGreaterThanReturnsTrue(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 0);
        $rank2 = Rank::forBoat(flexibility: 0, absence: 1);

        // Assert
        $this->assertTrue($rank1->isGreaterThan($rank2));
    }

    public function testIsGreaterThanReturnsFalse(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 0, absence: 1);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 0);

        // Assert
        $this->assertFalse($rank1->isGreaterThan($rank2));
    }

    public function testIsLessThanReturnsTrue(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 0, absence: 1);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 0);

        // Assert
        $this->assertTrue($rank1->isLessThan($rank2));
    }

    public function testIsLessThanReturnsFalse(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 0);
        $rank2 = Rank::forBoat(flexibility: 0, absence: 1);

        // Assert
        $this->assertFalse($rank1->isLessThan($rank2));
    }

    public function testEqualsReturnsTrue(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 2);

        // Assert
        $this->assertTrue($rank1->equals($rank2));
    }

    public function testEqualsReturnsFalse(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = Rank::forBoat(flexibility: 1, absence: 3);

        // Assert
        $this->assertFalse($rank1->equals($rank2));
    }

    public function testToStringReturnsFormattedArray(): void
    {
        // Arrange
        $rank = Rank::forBoat(flexibility: 1, absence: 2);

        // Assert
        $this->assertEquals('[1, 2]', (string) $rank);
    }

    public function testImmutability(): void
    {
        // Arrange
        $rank1 = Rank::forBoat(flexibility: 1, absence: 2);
        $rank2 = $rank1->withDimension(BoatRankDimension::ABSENCE, 5);

        // Original rank should be unchanged
        // Assert
        $this->assertEquals(2, $rank1->getDimension(BoatRankDimension::ABSENCE));
        $this->assertEquals(5, $rank2->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testCompareToWithDifferentDimensionCounts(): void
    {
        // Arrange
        $boatRank = Rank::forBoat(flexibility: 1, absence: 0);
        $crewRank = Rank::forCrew(
            availability: 0,
            commitment: 1,
            membership: 0,
            absence: 3
        );

        // Should compare the dimensions they have in common
        // Act
        $result = $boatRank->compareTo($crewRank);

        // First dimension: 1 vs 0 (boat higher), so boat ranks higher
        // Assert
        $this->assertGreaterThan(0, $result);
    }
}
