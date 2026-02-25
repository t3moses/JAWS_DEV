<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Entity;

use App\Domain\Entity\Boat;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;
use PHPUnit\Framework\TestCase;

class BoatTest extends TestCase
{
    private function createBoat(): Boat
    {
        return new Boat(
            key: BoatKey::fromString('sailaway'),
            displayName: 'Sail Away',
            ownerFirstName: 'John',
            ownerLastName: 'Doe',
            ownerMobile: '555-1234',
            minBerths: 1,
            maxBerths: 3,
            assistanceRequired: false,
            socialPreference: true
        );
    }

    public function testConstructorSetsProperties(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Assert
        $this->assertEquals('sailaway', $boat->getKey()->toString());
        $this->assertEquals('Sail Away', $boat->getDisplayName());
        $this->assertEquals('John', $boat->getOwnerFirstName());
        $this->assertEquals('Doe', $boat->getOwnerLastName());
        $this->assertEquals('555-1234', $boat->getOwnerMobile());
        $this->assertEquals(1, $boat->getMinBerths());
        $this->assertEquals(3, $boat->getMaxBerths());
        $this->assertFalse($boat->requiresAssistance());
        $this->assertTrue($boat->hasSocialPreference());
    }

    public function testConstructorInitializesDefaultRank(): void
    {
        // Arrange
        $boat = $this->createBoat();

        $rank = $boat->getRank();
        // Assert
        $this->assertEquals(1, $rank->getDimension(BoatRankDimension::FLEXIBILITY));
        $this->assertEquals(0, $rank->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testIdStartsAsNull(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Assert
        $this->assertNull($boat->getId());
    }

    public function testSetIdUpdatesId(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $boat->setId(42);

        // Assert
        $this->assertEquals(42, $boat->getId());
    }

    public function testGetOwnerKeyCreatesCorrectCrewKey(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Act
        $ownerKey = $boat->getOwnerKey();
        // Assert
        $this->assertEquals('johndoe', $ownerKey->toString());
    }

    public function testSetters(): void
    {
        // Arrange
        $boat = $this->createBoat();

        $boat->setDisplayName('New Name');
        $boat->setOwnerFirstName('Jane');
        $boat->setOwnerLastName('Smith');
        $boat->setOwnerMobile('555-5678');
        $boat->setMinBerths(2);
        $boat->setMaxBerths(4);
        $boat->setAssistanceRequired(true);
        $boat->setSocialPreference(false);

        // Assert
        $this->assertEquals('New Name', $boat->getDisplayName());
        $this->assertEquals('Jane', $boat->getOwnerFirstName());
        $this->assertEquals('Smith', $boat->getOwnerLastName());
        $this->assertEquals('555-5678', $boat->getOwnerMobile());
        $this->assertEquals(2, $boat->getMinBerths());
        $this->assertEquals(4, $boat->getMaxBerths());
        $this->assertTrue($boat->requiresAssistance());
        $this->assertFalse($boat->hasSocialPreference());
    }

    public function testSetAndGetRank(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $rank = Rank::forBoat(flexibility: 0, absence: 3);

        // Act
        $boat->setRank($rank);

        // Assert
        $this->assertEquals($rank, $boat->getRank());
    }

    public function testSetRankDimension(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Act
        $boat->setRankDimension(BoatRankDimension::ABSENCE, 5);

        // Assert
        $this->assertEquals(5, $boat->getRank()->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testUpdateAbsenceRankWithNoAbsences(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $boat->setHistory($eventId1, 'Y');
        $boat->setHistory($eventId2, 'Y');

        // Act
        $boat->updateAbsenceRank(['Fri May 29', 'Sat May 30']);

        // Assert
        $this->assertEquals(0, $boat->getRank()->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testUpdateAbsenceRankWithAbsences(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');
        $eventId3 = EventId::fromString('Sun May 31');

        $boat->setHistory($eventId1, 'Y');
        $boat->setHistory($eventId2, '');
        $boat->setHistory($eventId3, '');

        // Act
        $boat->updateAbsenceRank(['Fri May 29', 'Sat May 30', 'Sun May 31']);

        // Assert
        $this->assertEquals(2, $boat->getRank()->getDimension(BoatRankDimension::ABSENCE));
    }

    public function testGetBerthsReturnsZeroByDefault(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertEquals(0, $boat->getBerths($eventId));
    }

    public function testSetAndGetBerths(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Act
        $boat->setBerths($eventId, 2);

        // Assert
        $this->assertEquals(2, $boat->getBerths($eventId));
    }

    public function testGetAllBerths(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $boat->setBerths($eventId1, 2);
        $boat->setBerths($eventId2, 3);

        // Act
        $berths = $boat->getAllBerths();

        // Assert
        $this->assertEquals(2, $berths['Fri May 29']);
        $this->assertEquals(3, $berths['Sat May 30']);
    }

    public function testSetAllBerths(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventIds = [
            EventId::fromString('Fri May 29'),
            EventId::fromString('Sat May 30')
        ];

        // Act
        $boat->setAllBerths($eventIds, 2);

        // Assert
        $this->assertEquals(2, $boat->getBerths($eventIds[0]));
        $this->assertEquals(2, $boat->getBerths($eventIds[1]));
    }

    public function testIsAvailableForReturnsFalseWhenNoBerths(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertFalse($boat->isAvailableFor($eventId));
    }

    public function testIsAvailableForReturnsTrueWhenBerthsAvailable(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        $boat->setBerths($eventId, 2);

        // Assert
        $this->assertTrue($boat->isAvailableFor($eventId));
    }

    public function testGetHistoryReturnsEmptyStringByDefault(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertEquals('', $boat->getHistory($eventId));
    }

    public function testSetAndGetHistory(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Act
        $boat->setHistory($eventId, 'Y');

        // Assert
        $this->assertEquals('Y', $boat->getHistory($eventId));
    }

    public function testGetAllHistory(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $boat->setHistory($eventId1, 'Y');
        $boat->setHistory($eventId2, '');

        // Act
        $history = $boat->getAllHistory();

        // Assert
        $this->assertEquals('Y', $history['Fri May 29']);
        $this->assertEquals('', $history['Sat May 30']);
    }

    public function testSetAllHistory(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventIds = [
            EventId::fromString('Fri May 29'),
            EventId::fromString('Sat May 30')
        ];

        // Act
        $boat->setAllHistory($eventIds, 'Y');

        // Assert
        $this->assertEquals('Y', $boat->getHistory($eventIds[0]));
        $this->assertEquals('Y', $boat->getHistory($eventIds[1]));
    }

    public function testDidParticipateReturnsTrueForY(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        $boat->setHistory($eventId, 'Y');

        // Assert
        $this->assertTrue($boat->didParticipate($eventId));
    }

    public function testDidParticipateReturnsFalseForEmptyString(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        $boat->setHistory($eventId, '');

        // Assert
        $this->assertFalse($boat->didParticipate($eventId));
    }

    public function testDidParticipateReturnsFalseForUnsetHistory(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $eventId = EventId::fromString('Fri May 29');

        // Act & Assert
        $this->assertFalse($boat->didParticipate($eventId));
    }

    public function testToArrayReturnsCompleteArray(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $boat->setId(1);

        // Act
        $array = $boat->toArray();

        // Assert
        $this->assertEquals(1, $array['id']);
        $this->assertEquals('sailaway', $array['key']);
        $this->assertEquals('Sail Away', $array['display_name']);
        $this->assertEquals('John', $array['owner_first_name']);
        $this->assertEquals('Doe', $array['owner_last_name']);
        $this->assertEquals('555-1234', $array['owner_mobile']);
        $this->assertEquals(1, $array['min_berths']);
        $this->assertEquals(3, $array['max_berths']);
        $this->assertFalse($array['assistance_required']);
        $this->assertTrue($array['social_preference']);
        $this->assertIsArray($array['rank']);
        $this->assertIsArray($array['berths']);
        $this->assertIsArray($array['history']);
    }

    public function testGetOwnerDisplayNameFormatsCorrectly(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Act
        $displayName = $boat->getOwnerDisplayName();

        // Assert — "JohnD" format (first name + uppercase first letter of last name)
        $this->assertEquals('JohnD', $displayName);
    }

    public function testGetOwnerDisplayNameWithMultiWordLastName(): void
    {
        // Arrange
        $boat = new Boat(
            key: BoatKey::fromString('sailaway'),
            displayName: 'Sail Away',
            ownerFirstName: 'Mary',
            ownerLastName: 'van Houten',
            ownerMobile: '555-1234',
            minBerths: 1,
            maxBerths: 3,
            assistanceRequired: false,
            socialPreference: true
        );

        // Act
        $displayName = $boat->getOwnerDisplayName();

        // Assert — only first character of last name, uppercased
        $this->assertEquals('MaryV', $displayName);
    }

    public function testOccupiedBerthsCanBeSetDirectly(): void
    {
        // Arrange
        $boat = $this->createBoat();

        // Act
        $boat->occupied_berths = 2;

        // Assert
        $this->assertEquals(2, $boat->occupied_berths);
    }

    public function testIsWillingToCrewReturnsTrueWhenFlexibilityRankIsZero(): void
    {
        // Arrange
        $boat = $this->createBoat();
        $boat->setRankDimension(BoatRankDimension::FLEXIBILITY, 0);

        // Assert
        $this->assertTrue($boat->isWillingToCrew());
    }

    public function testIsWillingToCrewReturnsFalseByDefault(): void
    {
        // Arrange — default rank has flexibility=1
        $boat = $this->createBoat();

        // Assert
        $this->assertFalse($boat->isWillingToCrew());
    }

    public function testIsWillingToCrewIsBasedOnStoredRankNotOwnerName(): void
    {
        // Arrange — two boats with same owner but different flex ranks
        $flexBoat = $this->createBoat();
        $flexBoat->setRankDimension(BoatRankDimension::FLEXIBILITY, 0);

        $notFlexBoat = new Boat(
            key: BoatKey::fromString('seabreeze'),
            displayName: 'Sea Breeze',
            ownerFirstName: 'John',
            ownerLastName: 'Doe',
            ownerMobile: '555-1234',
            minBerths: 1,
            maxBerths: 3,
            assistanceRequired: false,
            socialPreference: true
        );

        // Assert
        $this->assertTrue($flexBoat->isWillingToCrew());
        $this->assertFalse($notFlexBoat->isWillingToCrew());
    }
}
