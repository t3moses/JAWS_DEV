<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Entity;

use App\Domain\Entity\Crew;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\CrewRankDimension;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use PHPUnit\Framework\TestCase;

class CrewTest extends TestCase
{
    private function createCrew(): Crew
    {
        return new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: '12345',
            skill: SkillLevel::INTERMEDIATE,
            experience: '5 years'
        );
    }

    public function testConstructorSetsProperties(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Assert
        $this->assertEquals('johndoe', $crew->getKey()->toString());
        $this->assertEquals('John Doe', $crew->getDisplayName());
        $this->assertEquals('John', $crew->getFirstName());
        $this->assertEquals('Doe', $crew->getLastName());
        $this->assertNull($crew->getPartnerKey());
        $this->assertEquals('555-1234', $crew->getMobile());
        $this->assertTrue($crew->hasSocialPreference());
        $this->assertEquals('12345', $crew->getMembershipNumber());
        $this->assertEquals(SkillLevel::INTERMEDIATE, $crew->getSkill());
        $this->assertEquals('5 years', $crew->getExperience());
    }

    public function testConstructorInitializesDefaultRank(): void
    {
        // Arrange
        $crew = $this->createCrew();

        $rank = $crew->getRank();
        // Assert
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::COMMITMENT));
        $this->assertEquals(1, $rank->getDimension(CrewRankDimension::MEMBERSHIP)); // Valid membership number '12345'
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testConstructorSetsDefaultRankWithoutMembership(): void
    {
        // Arrange
        $crew = new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::NOVICE,
            experience: null
        );

        $rank = $crew->getRank();
        // Assert
        $this->assertEquals(0, $rank->getDimension(CrewRankDimension::MEMBERSHIP)); // No membership (invalid)
    }

    public function testIdStartsAsNull(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Assert
        $this->assertNull($crew->getId());
    }

    public function testSetIdUpdatesId(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $crew->setId(42);

        // Assert
        $this->assertEquals(42, $crew->getId());
    }

    public function testSetters(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $partnerKey = CrewKey::fromString('janedoe');

        $crew->setDisplayName('Jane Doe');
        $crew->setFirstName('Jane');
        $crew->setLastName('Doe');
        $crew->setPartnerKey($partnerKey);
        $crew->setMobile('555-5678');
        $crew->setSocialPreference(false);
        $crew->setMembershipNumber('54321');
        $crew->setSkill(SkillLevel::ADVANCED);
        $crew->setExperience('10 years');

        // Assert
        $this->assertEquals('Jane Doe', $crew->getDisplayName());
        $this->assertEquals('Jane', $crew->getFirstName());
        $this->assertEquals('Doe', $crew->getLastName());
        $this->assertEquals($partnerKey, $crew->getPartnerKey());
        $this->assertEquals('555-5678', $crew->getMobile());
        $this->assertFalse($crew->hasSocialPreference());
        $this->assertEquals('54321', $crew->getMembershipNumber());
        $this->assertEquals(SkillLevel::ADVANCED, $crew->getSkill());
        $this->assertEquals('10 years', $crew->getExperience());
    }

    public function testHasPartnerReturnsFalseWhenNoPartner(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Assert
        $this->assertFalse($crew->hasPartner());
    }

    public function testHasPartnerReturnsTrueWhenPartnerSet(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $crew->setPartnerKey(CrewKey::fromString('janedoe'));

        // Assert
        $this->assertTrue($crew->hasPartner());
    }

    public function testIsMemberReturnsTrueWithMembershipNumber(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Assert
        $this->assertTrue($crew->isMember());
    }

    public function testIsMemberReturnsFalseWithoutMembershipNumber(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $crew->setMembershipNumber(null);

        // Assert
        $this->assertFalse($crew->isMember());
    }

    public function testSetMembershipNumberUpdatesRank(): void
    {
        // Arrange
        $crew = $this->createCrew();

        $crew->setMembershipNumber(null);
        // Assert - null is invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));

        $crew->setMembershipNumber('12345');
        // Valid membership (5 digits, numeric), should get rank 1
        $this->assertEquals(1, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetAndGetRank(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $rank = Rank::forCrew(
            availability: 0,
            commitment: 1,
            membership: 1,
            absence: 2
        );

        $crew->setRank($rank);

        // Assert
        $this->assertEquals($rank, $crew->getRank());
    }

    public function testSetRankDimension(): void
    {
        // Arrange
        $crew = $this->createCrew();

        $crew->setRankDimension(CrewRankDimension::ABSENCE, 5);

        // Assert
        $this->assertEquals(5, $crew->getRank()->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testUpdateAbsenceRankWithNoAbsences(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $crew->setHistory($eventId1, 'sailaway');
        $crew->setHistory($eventId2, 'anotherboat');

        $crew->updateAbsenceRank(['Fri May 29', 'Sat May 30']);

        // Assert
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testUpdateAbsenceRankWithAbsences(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');
        $eventId3 = EventId::fromString('Sun May 31');

        $crew->setHistory($eventId1, 'sailaway');
        $crew->setHistory($eventId2, '');
        $crew->setHistory($eventId3, '');

        $crew->updateAbsenceRank(['Fri May 29', 'Sat May 30', 'Sun May 31']);

        // Assert
        $this->assertEquals(2, $crew->getRank()->getDimension(CrewRankDimension::ABSENCE));
    }

    public function testGetAvailabilityReturnsUnavailableByDefault(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE, $crew->getAvailability($eventId));
    }

    public function testSetAndGetAvailability(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setAvailability($eventId, AvailabilityStatus::AVAILABLE);

        // Assert
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $crew->getAvailability($eventId));
    }

    public function testGetAllAvailability(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $crew->setAvailability($eventId1, AvailabilityStatus::AVAILABLE);
        $crew->setAvailability($eventId2, AvailabilityStatus::GUARANTEED);

        $availability = $crew->getAllAvailability();

        // Assert
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $availability['Fri May 29']);
        $this->assertEquals(AvailabilityStatus::GUARANTEED, $availability['Sat May 30']);
    }

    public function testSetAllAvailability(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventIds = [
            EventId::fromString('Fri May 29'),
            EventId::fromString('Sat May 30')
        ];

        $crew->setAllAvailability($eventIds, AvailabilityStatus::AVAILABLE);

        // Assert
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $crew->getAvailability($eventIds[0]));
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $crew->getAvailability($eventIds[1]));
    }

    public function testIsAvailableForReturnsFalseWhenUnavailable(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertFalse($crew->isAvailableFor($eventId));
    }

    public function testIsAvailableForReturnsTrueWhenAvailable(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setAvailability($eventId, AvailabilityStatus::AVAILABLE);

        // Assert
        $this->assertTrue($crew->isAvailableFor($eventId));
    }

    public function testIsAvailableForReturnsTrueWhenGuaranteed(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setAvailability($eventId, AvailabilityStatus::GUARANTEED);

        // Assert
        $this->assertTrue($crew->isAvailableFor($eventId));
    }

    public function testIsAssignedToReturnsFalseWhenNotAssigned(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setAvailability($eventId, AvailabilityStatus::AVAILABLE);

        // Assert
        $this->assertFalse($crew->isAssignedTo($eventId));
    }

    public function testIsAssignedToReturnsTrueWhenGuaranteed(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setAvailability($eventId, AvailabilityStatus::GUARANTEED);

        // Assert
        $this->assertTrue($crew->isAssignedTo($eventId));
    }

    public function testGetHistoryReturnsEmptyStringByDefault(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertEquals('', $crew->getHistory($eventId));
    }

    public function testSetAndGetHistory(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        // Act
        $crew->setHistory($eventId, 'sailaway');

        // Assert
        $this->assertEquals('sailaway', $crew->getHistory($eventId));
    }

    public function testGetAllHistory(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId1 = EventId::fromString('Fri May 29');
        $eventId2 = EventId::fromString('Sat May 30');

        $crew->setHistory($eventId1, 'sailaway');
        $crew->setHistory($eventId2, 'anotherboat');

        // Act
        $history = $crew->getAllHistory();

        // Assert
        $this->assertEquals('sailaway', $history['Fri May 29']);
        $this->assertEquals('anotherboat', $history['Sat May 30']);
    }

    public function testSetAllHistory(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventIds = [
            EventId::fromString('Fri May 29'),
            EventId::fromString('Sat May 30')
        ];

        // Act
        $crew->setAllHistory($eventIds, 'sailaway');

        // Assert
        $this->assertEquals('sailaway', $crew->getHistory($eventIds[0]));
        $this->assertEquals('sailaway', $crew->getHistory($eventIds[1]));
    }

    public function testWasAssignedToReturnsNullWhenNoHistory(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        // Assert
        $this->assertNull($crew->wasAssignedTo($eventId));
    }

    public function testWasAssignedToReturnsBoatKey(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $eventId = EventId::fromString('Fri May 29');

        $crew->setHistory($eventId, 'sailaway');

        // Act
        $boatKey = $crew->wasAssignedTo($eventId);

        // Assert
        $this->assertNotNull($boatKey);
        $this->assertEquals('sailaway', $boatKey->toString());
    }

    public function testGetWhitelistReturnsEmptyArrayByDefault(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Assert
        $this->assertEmpty($crew->getWhitelist());
    }

    public function testAddToWhitelist(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $boatKey = BoatKey::fromString('sailaway');

        // Act
        $crew->addToWhitelist($boatKey);

        // Assert
        $whitelist = $crew->getWhitelist();
        $this->assertCount(1, $whitelist);
        $this->assertEquals('sailaway', $whitelist[0]);
    }

    public function testAddToWhitelistPreventsDuplicates(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $boatKey = BoatKey::fromString('sailaway');

        // Act
        $crew->addToWhitelist($boatKey);
        $crew->addToWhitelist($boatKey);

        // Assert
        $whitelist = $crew->getWhitelist();
        $this->assertCount(1, $whitelist);
    }

    public function testRemoveFromWhitelist(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $boatKey1 = BoatKey::fromString('sailaway');
        $boatKey2 = BoatKey::fromString('anotherboat');

        $crew->addToWhitelist($boatKey1);
        $crew->addToWhitelist($boatKey2);

        // Act
        $crew->removeFromWhitelist($boatKey1);

        // Assert
        $whitelist = $crew->getWhitelist();
        $this->assertCount(1, $whitelist);
        $this->assertEquals('anotherboat', $whitelist[0]);
    }

    public function testIsWhitelistedReturnsFalseWhenNotInWhitelist(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $boatKey = BoatKey::fromString('sailaway');

        // Assert
        $this->assertFalse($crew->isWhitelisted($boatKey));
    }

    public function testIsWhitelistedReturnsTrueWhenInWhitelist(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $boatKey = BoatKey::fromString('sailaway');

        $crew->addToWhitelist($boatKey);

        // Assert
        $this->assertTrue($crew->isWhitelisted($boatKey));
    }

    public function testSetWhitelist(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setWhitelist(['sailaway', 'anotherboat']);

        $whitelist = $crew->getWhitelist();
        // Assert
        $this->assertCount(2, $whitelist);
        $this->assertEquals('sailaway', $whitelist[0]);
        $this->assertEquals('anotherboat', $whitelist[1]);
    }

    public function testToArrayReturnsCompleteArray(): void
    {
        // Arrange
        $crew = $this->createCrew();
        $crew->setId(1);

        $array = $crew->toArray();

        // Assert
        $this->assertEquals(1, $array['id']);
        $this->assertEquals('johndoe', $array['key']);
        $this->assertEquals('John Doe', $array['display_name']);
        $this->assertEquals('John', $array['first_name']);
        $this->assertEquals('Doe', $array['last_name']);
        $this->assertNull($array['partner_key']);
        $this->assertEquals('555-1234', $array['mobile']);
        $this->assertTrue($array['social_preference']);
        $this->assertEquals('12345', $array['membership_number']);
        $this->assertEquals(SkillLevel::INTERMEDIATE->value, $array['skill']);
        $this->assertEquals('5 years', $array['experience']);
        $this->assertIsArray($array['rank']);
        $this->assertIsArray($array['availability']);
        $this->assertIsArray($array['history']);
        $this->assertIsArray($array['whitelist']);
    }

    // Membership validation tests

    public function testConstructorWithMinimumValidMembershipLength(): void
    {
        // Arrange
        $crew = new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: '1234', // Minimum valid length
            skill: SkillLevel::NOVICE,
            experience: null
        );

        // Assert - 4 digits is valid, should get rank 1
        $this->assertEquals(1, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testConstructorWithMaximumValidMembershipLength(): void
    {
        // Arrange
        $crew = new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: '123456789', // Maximum valid length
            skill: SkillLevel::NOVICE,
            experience: null
        );

        // Assert - 9 digits is valid, should get rank 1
        $this->assertEquals(1, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testConstructorWithTooShortMembership(): void
    {
        // Arrange
        $crew = new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: '123', // Too short
            skill: SkillLevel::NOVICE,
            experience: null
        );

        // Assert - 3 digits is invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testConstructorWithTooLongMembership(): void
    {
        // Arrange
        $crew = new Crew(
            key: CrewKey::fromString('johndoe'),
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: '1234567890', // Too long
            skill: SkillLevel::NOVICE,
            experience: null
        );

        // Assert - 10 digits is invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithLetters(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('12A45');

        // Assert - contains letters, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithAllLetters(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('ABCD');

        // Assert - all letters, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithDashesGetsCleaned(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('12-34-56');

        // Assert - after removing dashes → "123456" (6 digits), valid, should get rank 1
        $this->assertEquals(1, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithSpacesGetsCleaned(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('  12345  ');

        // Assert - after removing spaces → "12345" (5 digits), valid, should get rank 1
        $this->assertEquals(1, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithOnlySpecialChars(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('----');

        // Assert - after cleaning → "", empty, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithEmptyString(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('');

        // Assert - empty string, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithMixedAlphanumeric(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('ABC-123');

        // Assert - after removing dash → "ABC123", contains letters, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }

    public function testSetMembershipNumberWithOnlySpaces(): void
    {
        // Arrange
        $crew = $this->createCrew();

        // Act
        $crew->setMembershipNumber('   ');

        // Assert - after removing spaces → "", empty, invalid, should get rank 0
        $this->assertEquals(0, $crew->getRank()->getDimension(CrewRankDimension::MEMBERSHIP));
    }
}
