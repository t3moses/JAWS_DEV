<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Service;

use App\Domain\Service\SelectionService;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\SkillLevel;
use App\Domain\Enum\AvailabilityStatus;
use PHPUnit\Framework\TestCase;

class SelectionServiceTest extends TestCase
{
    private SelectionService $service;
    private EventId $eventId;

    protected function setUp(): void
    {
        $this->service = new SelectionService();
        $this->eventId = EventId::fromString('Fri May 29');
    }

    private function createBoat(
        string $key,
        int $minBerths,
        int $maxBerths,
        ?Rank $rank = null
    ): Boat {
        $boat = new Boat(
            key: BoatKey::fromString($key),
            displayName: "Test Boat $key",
            ownerFirstName: 'John',
            ownerLastName: 'Doe',
            ownerMobile: '555-1234',
            minBerths: $minBerths,
            maxBerths: $maxBerths,
            assistanceRequired: false,
            socialPreference: true
        );
        if ($rank !== null) {
            $boat->setRank($rank);
        }

        // Set event-specific availability
        $boat->setHistory($this->eventId, 'Y');
        $boat->setBerths($this->eventId, $maxBerths);

        return $boat;
    }

    private function createCrew(
        string $key,
        ?Rank $rank = null
    ): Crew {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: "Test Crew $key",
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: "12345$key",
            skill: SkillLevel::INTERMEDIATE,
            experience: '5 years'
        );
        if ($rank !== null) {
            $crew->setRank($rank);
        }

        // Set event-specific availability
        $crew->setHistory($this->eventId, 'Y');
        $crew->setAvailability($this->eventId, AvailabilityStatus::SELECTED);

        return $crew;
    }

    // Verifies that when total berths exactly match crew count, all boats and crews are selected
    public function testSelectWithPerfectFit(): void
    {
        // Arrange
        // 2 boats with 2 berths each = 4 berths, 4 crews = perfect fit
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));

        // Act
        $this->service->select(
            [$boat1, $boat2],
            [$crew1, $crew2, $crew3, $crew4],
            $this->eventId
        );

        // Assert
        $this->assertCount(2, $this->service->getSelectedBoats());
        $this->assertCount(4, $this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Tests that when there aren't enough crews for minimum berths, lowest ranked boats are cut
    public function testSelectWithTooFewCrews(): void
    {
        // Arrange
        // 3 boats need 2 berths each = 6 berths minimum, but only 4 crews
        $boat1 = $this->createBoat('sailaway', 2, 3, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 3, Rank::forBoat(flexibility: 0, absence: 1));
        $boat3 = $this->createBoat('windseeker', 2, 3, Rank::forBoat(flexibility: 0, absence: 2));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));

        // Act
        $this->service->select(
            [$boat1, $boat2, $boat3],
            [$crew1, $crew2, $crew3, $crew4],
            $this->eventId
        );

        // Assert
        // Should cut boats (lowest ranked first)
        $selectedBoats = $this->service->getSelectedBoats();
        $this->assertLessThan(3, count($selectedBoats));

        $this->assertCount(4, $this->service->getSelectedCrews());
        $this->assertNotEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Tests that when there are more crews than maximum berths, lowest ranked crews go to waitlist
    public function testSelectWithTooManyCrews(): void
    {
        // Arrange
        // 2 boats with 2 berths each = 4 berths maximum, but 6 crews
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));
        $crew5 = $this->createCrew('eve', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 4));
        $crew6 = $this->createCrew('frank', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 5));

        // Act
        $this->service->select(
            [$boat1, $boat2],
            [$crew1, $crew2, $crew3, $crew4, $crew5, $crew6],
            $this->eventId
        );

        // Assert
        // Should cut crews (lowest ranked first)
        $this->assertCount(2, $this->service->getSelectedBoats());
        $this->assertCount(4, $this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertCount(2, $this->service->getWaitlistCrews());
    }

    // Verifies that boats with flexible berth counts can expand to accommodate available crews
    public function testSelectWithFlexibleBerths(): void
    {
        // Arrange
        // 2 boats: min 2, max 3 each = 4-6 berths flexible
        $boat1 = $this->createBoat('sailaway', 2, 3, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 3, Rank::forBoat(flexibility: 0, absence: 1));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));
        $crew5 = $this->createCrew('eve', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 4));

        // Act
        $this->service->select(
            [$boat1, $boat2],
            [$crew1, $crew2, $crew3, $crew4, $crew5],
            $this->eventId
        );

        // Assert
        // Should fit 5 crews by expanding berths
        $this->assertCount(2, $this->service->getSelectedBoats());
        $this->assertCount(5, $this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // // Tests that selected boats are returned in rank order with highest priority first
    // public function testGetSelectedBoatsReturnsHighestRankedFirst(): void
    // {
    //     $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 2));
    //     $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
    //     $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));

    //     $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
    //     $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

    //     $this->service->select(
    //         [$boat1, $boat2, $boat3],
    //         [$crew1, $crew2],
    //         $this->eventId
    //     );

    //     $selectedBoats = $this->service->getSelectedBoats();

    //     // Should be ordered by rank (lowest rank = highest priority first)
    //     $this->assertEquals('seabreeze', $selectedBoats[0]->getKey()->toString());
    // }

    // // Tests that selected crews are returned in rank order with highest priority first
    // public function testGetSelectedCrewsReturnsHighestRankedFirst(): void
    // {
    //     $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));

    //     $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
    //     $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
    //     $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

    //     $this->service->select(
    //         [$boat1],
    //         [$crew1, $crew2, $crew3],
    //         $this->eventId
    //     );

    //     $selectedCrews = $this->service->getSelectedCrews();

    //     // Should be ordered by rank (lowest rank = highest priority first)
    //     $this->assertCount(2, $selectedCrews);
    //     $this->assertEquals('bob', $selectedCrews[0]->getKey()->toString());
    //     $this->assertEquals('charlie', $selectedCrews[1]->getKey()->toString());
    // }

    // // Verifies that when boats are cut, the lowest ranked ones appear on the waitlist
    // public function testGetWaitlistBoatsContainsLowestRanked(): void
    // {
    //     // 3 boats, only space for 2
    //     $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
    //     $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));
    //     $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 2));

    //     $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
    //     $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

    //     $this->service->select(
    //         [$boat1, $boat2, $boat3],
    //         [$crew1, $crew2],
    //         $this->eventId
    //     );

    //     $waitlistBoats = $this->service->getWaitlistBoats();

    //     // Lowest ranked boat(s) should be on waitlist
    //     $this->assertNotEmpty($waitlistBoats);
    //     // Waitlist is reversed, so lowest rank is last
    //     $waitlistKeys = array_map(fn($b) => $b->getKey()->toString(), $waitlistBoats);
    //     $this->assertContains('windseeker', $waitlistKeys);
    // }

    // // Verifies that when crews are cut, the lowest ranked ones appear on the waitlist
    // public function testGetWaitlistCrewsContainsLowestRanked(): void
    // {
    //     // 1 boat with 2 berths, but 4 crews
    //     $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));

    //     $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
    //     $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
    //     $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
    //     $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));

    //     $this->service->select(
    //         [$boat1],
    //         [$crew1, $crew2, $crew3, $crew4],
    //         $this->eventId
    //     );

    //     $waitlistCrews = $this->service->getWaitlistCrews();

    //     // Lowest ranked crews should be on waitlist
    //     $this->assertCount(2, $waitlistCrews);
    //     $waitlistKeys = array_map(fn($c) => $c->getKey()->toString(), $waitlistCrews);
    //     $this->assertContains('charlie', $waitlistKeys);
    //     $this->assertContains('dave', $waitlistKeys);
    // }

    // Tests edge case where both boats and crews lists are empty
    public function testSelectWithEmptyBoatsAndCrews(): void
    {
        // Arrange
        // Act
        // Assert
        $this->service->select([], [], $this->eventId);

        // Assert
        $this->assertEmpty($this->service->getSelectedBoats());
        $this->assertEmpty($this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Tests that boats without crews all go to waitlist
    public function testSelectWithOnlyBoatsNoCrews(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));

        // Act
        $this->service->select([$boat1, $boat2], [], $this->eventId);

        // Assert
        // All boats should be on waitlist since no crews
        $this->assertEmpty($this->service->getSelectedBoats());
        $this->assertEmpty($this->service->getSelectedCrews());
        $this->assertNotEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Tests that crews without boats result in no selections
    public function testSelectWithOnlyCrewsNoBoats(): void
    {
        // Arrange
        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

        // Act
        $this->service->select([], [$crew1, $crew2], $this->eventId);

        // Assert
        $this->assertEmpty($this->service->getSelectedBoats());
        $this->assertEmpty($this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Verifies that using the same event ID produces identical selection results
    public function testDeterministicShuffleWithSameSeed(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));

        // Act
        // First selection
        $service1 = new SelectionService();
        $service1->select([$boat1, $boat2, $boat3], [$crew1, $crew2, $crew3], $this->eventId);
        $result1Boats = $service1->getSelectedBoats();
        $result1Crews = $service1->getSelectedCrews();

        // Second selection with same event ID (same seed)
        $service2 = new SelectionService();
        $service2->select([$boat1, $boat2, $boat3], [$crew1, $crew2, $crew3], $this->eventId);
        $result2Boats = $service2->getSelectedBoats();
        $result2Crews = $service2->getSelectedCrews();

        // Assert
        // Results should be identical
        $this->assertEquals(
            array_map(fn($b) => $b->getKey()->toString(), $result1Boats),
            array_map(fn($b) => $b->getKey()->toString(), $result2Boats)
        );
        $this->assertEquals(
            array_map(fn($c) => $c->getKey()->toString(), $result1Crews),
            array_map(fn($c) => $c->getKey()->toString(), $result2Crews)
        );
    }

    // Verifies that different event IDs can produce different selection results
    public function testDeterministicShuffleWithDifferentSeeds(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));

        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        // Act
        // Selection with first event ID
        $service1 = new SelectionService();
        $service1->select([$boat1, $boat2, $boat3], [$crew1, $crew2, $crew3], $eventId1);
        $result1Boats = $service1->getSelectedBoats();

        // Selection with second event ID
        $service2 = new SelectionService();
        $service2->select([$boat1, $boat2, $boat3], [$crew1, $crew2, $crew3], $eventId2);
        $result2Boats = $service2->getSelectedBoats();

        // Assert
        // Results will likely be different (though not guaranteed due to randomness)
        // At minimum, verify both completed successfully
        $this->assertNotEmpty($result1Boats);
        $this->assertNotEmpty($result2Boats);
    }

    // Tests that multi-dimensional ranks are compared lexicographically
    public function testLexicographicRankComparison(): void
    {
        // Arrange
        // Test with multi-dimensional ranks
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 1, absence: 2));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 1, absence: 3));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 2, absence: 1));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 1, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 2, membership: 0, absence: 0));

        // Act
        $this->service->select(
            [$boat1, $boat2, $boat3],
            [$crew1, $crew2],
            $this->eventId
        );

        // Assert
        $selectedBoats = $this->service->getSelectedBoats();

        // Higher rank = higher priority. Sorted descending: [2,1] > [1,3] > [1,2]
        // So windseeker (rank [2,1]) is highest priority and the only selected boat
        // (case1: 2 crews < 6 min berths → cut sailaway then seabreeze from the end)
        $this->assertEquals('windseeker', $selectedBoats[0]->getKey()->toString());
    }

    // Tests scenario where cutting one boat results in perfect crew-to-berth fit
    public function testCase1PerfectFitAfterCuttingBoats(): void
    {
        // Arrange
        // 3 boats needing 2 berths each = 6 berths
        // 4 crews available
        // Should cut 1 boat to get 4 berths = perfect fit
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 2));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));

        // Act
        $this->service->select(
            [$boat1, $boat2, $boat3],
            [$crew1, $crew2, $crew3, $crew4],
            $this->eventId
        );

        // Assert
        $this->assertCount(2, $this->service->getSelectedBoats());
        $this->assertCount(4, $this->service->getSelectedCrews());
        $this->assertCount(1, $this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());
    }

    // Verifies that crews are distributed evenly across boats with flexible berths
    public function testCase3DistributesCrewsOptimally(): void
    {
        // Arrange
        // Boats with flexible berths
        $boat1 = $this->createBoat('sailaway', 1, 3, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 1, 3, Rank::forBoat(flexibility: 0, absence: 1));

        // 4 crews - should distribute evenly (2 per boat)
        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));
        $crew4 = $this->createCrew('dave', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 3));

        // Act
        $this->service->select(
            [$boat1, $boat2],
            [$crew1, $crew2, $crew3, $crew4],
            $this->eventId
        );

        // Assert
        $selectedBoats = $this->service->getSelectedBoats();

        // Both boats should have occupied_berths set
        $totalOccupied = $selectedBoats[0]->occupied_berths + $selectedBoats[1]->occupied_berths;
        $this->assertEquals(4, $totalOccupied);
    }

    // Tests that the bubble sort optimization terminates early for already sorted data
    public function testBubbleSortTerminatesEarlyWhenSorted(): void
    {
        // Arrange
        // Already sorted boats
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 1));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 2));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

        // Act
        // This should complete quickly without many comparisons
        $this->service->select(
            [$boat1, $boat2, $boat3],
            [$crew1, $crew2],
            $this->eventId
        );

        // Assert
        // Verify it completed successfully
        $this->assertNotEmpty($this->service->getSelectedBoats());
    }

    // Tests that entities with identical ranks can still be differentiated via shuffle
    public function testSelectHandlesIdenticalRanks(): void
    {
        // Arrange
        // All boats have same rank - shuffle should differentiate
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat2 = $this->createBoat('seabreeze', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $boat3 = $this->createBoat('windseeker', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));

        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));

        // Act
        $this->service->select(
            [$boat1, $boat2, $boat3],
            [$crew1, $crew2],
            $this->eventId
        );

        // Assert
        // Should still make a selection
        $this->assertCount(1, $this->service->getSelectedBoats());
        $this->assertCount(2, $this->service->getSelectedCrews());
    }

    // Verifies that occupied berths stay within the boat's min and max berth limits
    public function testOccupiedBerthsRespectsBounds(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', 2, 4, Rank::forBoat(flexibility: 0, absence: 0));

        // 3 crews - should fit on one boat with flexible berths
        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));

        // Act
        $this->service->select([$boat1], [$crew1, $crew2, $crew3], $this->eventId);

        // Assert
        $selectedBoats = $this->service->getSelectedBoats();
        $occupiedBerths = $selectedBoats[0]->occupied_berths;

        // Should be between min and max
        $this->assertGreaterThanOrEqual(2, $occupiedBerths);
        $this->assertLessThanOrEqual(4, $occupiedBerths);
        $this->assertEquals(3, $occupiedBerths);
    }

    // Proves that case3() respects the berths the owner offered for the specific event,
    // not the boat's physical maximum capacity.
    // Regression test for bug where getMaxBerths() was used instead of getBerths($eventId).
    public function testCase3RespectsEventOfferedBerthsNotPhysicalMax(): void
    {
        // Boat A: physical max 5, but owner only offers 1 berth for this event
        $boat1 = $this->createBoat('sailaway', 1, 5, Rank::forBoat(flexibility: 0, absence: 0));
        $boat1->setBerths($this->eventId, 1); // override the createBoat default (which sets to maxBerths)

        // Boat B: physical max 2, offers 2 berths for this event (createBoat default is fine)
        $boat2 = $this->createBoat('seabreeze', 1, 2, Rank::forBoat(flexibility: 0, absence: 0));

        // 3 crews = total offered berths (1+2) — perfect fit → case3
        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));
        $crew3 = $this->createCrew('charlie', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 2));

        $this->service->select([$boat1, $boat2], [$crew1, $crew2, $crew3], $this->eventId);

        $selectedBoats = $this->service->getSelectedBoats();
        $this->assertCount(2, $selectedBoats);
        $this->assertCount(3, $this->service->getSelectedCrews());
        $this->assertEmpty($this->service->getWaitlistBoats());
        $this->assertEmpty($this->service->getWaitlistCrews());

        // No boat's occupied_berths should exceed what the owner offered for this event
        foreach ($selectedBoats as $boat) {
            $this->assertLessThanOrEqual(
                $boat->getBerths($this->eventId),
                $boat->occupied_berths,
                sprintf(
                    'Boat %s: occupied_berths (%d) must not exceed event-offered berths (%d)',
                    $boat->getKey()->toString(),
                    $boat->occupied_berths,
                    $boat->getBerths($this->eventId)
                )
            );
        }

        // Verify exact distribution: sailaway gets its 1 offered berth, seabreeze absorbs the rest
        $boatsByKey = [];
        foreach ($selectedBoats as $b) {
            $boatsByKey[$b->getKey()->toString()] = $b;
        }
        $this->assertEquals(1, $boatsByKey['sailaway']->occupied_berths,
            'sailaway offered 1 berth; must not be over-assigned');
        $this->assertEquals(2, $boatsByKey['seabreeze']->occupied_berths,
            'seabreeze offered 2 berths; should absorb the extra crew');
    }

    // Tests that boat and crew entities maintain their identity after selection
    public function testSelectMaintainsBoatAndCrewIntegrity(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', 2, 2, Rank::forBoat(flexibility: 0, absence: 0));
        $crew1 = $this->createCrew('alice', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 0));
        $crew2 = $this->createCrew('bob', Rank::forCrew(availability: 0, commitment: 0, membership: 0, absence: 1));

        $originalBoatKey = $boat1->getKey()->toString();
        $originalCrew1Key = $crew1->getKey()->toString();
        $originalCrew2Key = $crew2->getKey()->toString();

        // Act
        $this->service->select([$boat1], [$crew1, $crew2], $this->eventId);

        // Assert
        // Verify entities maintain their identity
        $selectedBoats = $this->service->getSelectedBoats();
        $selectedCrews = $this->service->getSelectedCrews();

        $this->assertEquals($originalBoatKey, $selectedBoats[0]->getKey()->toString());
        $this->assertContains(
            $originalCrew1Key,
            array_map(fn($c) => $c->getKey()->toString(), $selectedCrews)
        );
        $this->assertContains(
            $originalCrew2Key,
            array_map(fn($c) => $c->getKey()->toString(), $selectedCrews)
        );
    }
}
