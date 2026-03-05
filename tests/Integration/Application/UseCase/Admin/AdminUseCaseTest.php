<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Admin;

use App\Application\UseCase\Admin\GetMatchingDataUseCase;
use App\Application\Exception\EventNotFoundException;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Admin UseCase operations
 *
 * Tests admin functionality including:
 * - Getting matching data (available boats/crews, capacity analysis)
 * - Sending notifications to participants
 */
class AdminUseCaseTest extends IntegrationTestCase
{
    private GetMatchingDataUseCase $getMatchingDataUseCase;
    private EventRepository $eventRepository;
    private BoatRepository $boatRepository;
    private CrewRepository $crewRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = new EventRepository();
        $this->boatRepository = new BoatRepository();
        $this->crewRepository = new CrewRepository();
        $this->userRepository = new UserRepository();

        $this->getMatchingDataUseCase = new GetMatchingDataUseCase(
            $this->boatRepository,
            $this->crewRepository,
            $this->eventRepository
        );

        $this->initializeTestData();
    }

    protected function initializeTestData(): void
    {
        // Create test event
        $this->createTestEvent('Fri May 15', '2026-05-15');

        // Create users
        $boatOwner1 = new User('boatowner1@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($boatOwner1);

        $boatOwner2 = new User('boatowner2@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($boatOwner2);

        $crewUser1 = new User('crew1@example.com', 'hash', 'crew', false);
        $this->userRepository->save($crewUser1);

        $crewUser2 = new User('crew2@example.com', 'hash', 'crew', false);
        $this->userRepository->save($crewUser2);

        $crewUser3 = new User('crew3@example.com', 'hash', 'crew', false);
        $this->userRepository->save($crewUser3);

        // Create boats
        $boat1 = new Boat(
            key: BoatKey::fromString('Boat One'),
            displayName: 'Boat One',
            ownerFirstName: 'Owner',
            ownerLastName: 'One',
            ownerMobile: '555-0001',
            minBerths: 2,
            maxBerths: 4,
            assistanceRequired: false,
            socialPreference: false
        );
        $boat1->setOwnerUserId($boatOwner1->getId());
        $this->boatRepository->save($boat1);

        $boat2 = new Boat(
            key: BoatKey::fromString('Boat Two'),
            displayName: 'Boat Two',
            ownerFirstName: 'Owner',
            ownerLastName: 'Two',
            ownerMobile: '555-0002',
            minBerths: 3,
            maxBerths: 5,
            assistanceRequired: false,
            socialPreference: false
        );
        $boat2->setOwnerUserId($boatOwner2->getId());
        $this->boatRepository->save($boat2);

        // Create crews
        $crew1 = new Crew(
            key: CrewKey::fromName('Alice', 'Smith'),
            displayName: 'Alice Smith',
            firstName: 'Alice',
            lastName: 'Smith',
            partnerKey: null,
            mobile: '1234567890',
            socialPreference: false,
            membershipNumber: 'M001',
            skill: SkillLevel::ADVANCED,
            experience: null
        );
        $crew1->setUserId($crewUser1->getId());
        $this->crewRepository->save($crew1);

        $crew2 = new Crew(
            key: CrewKey::fromName('Bob', 'Jones'),
            displayName: 'Bob Jones',
            firstName: 'Bob',
            lastName: 'Jones',
            partnerKey: null,
            mobile: '1234567891',
            socialPreference: false,
            membershipNumber: 'M002',
            skill: SkillLevel::INTERMEDIATE,
            experience: null
        );
        $crew2->setUserId($crewUser2->getId());
        $this->crewRepository->save($crew2);

        $crew3 = new Crew(
            key: CrewKey::fromName('Charlie', 'Brown'),
            displayName: 'Charlie Brown',
            firstName: 'Charlie',
            lastName: 'Brown',
            partnerKey: null,
            mobile: '1234567892',
            socialPreference: false,
            membershipNumber: 'M003',
            skill: SkillLevel::NOVICE,
            experience: null
        );
        $crew3->setUserId($crewUser3->getId());
        $this->crewRepository->save($crew3);

        // Set boat availability (both boats available)
        $eventId = EventId::fromString('Fri May 15');
        $boat1->setBerths($eventId, 4);
        $boat2->setBerths($eventId, 5);
        $this->boatRepository->save($boat1);
        $this->boatRepository->save($boat2);

        // Set crew availability (all crews available)
        $crew1->setAvailability($eventId, AvailabilityStatus::AVAILABLE);
        $crew2->setAvailability($eventId, AvailabilityStatus::AVAILABLE);
        $crew3->setAvailability($eventId, AvailabilityStatus::AVAILABLE);
        $this->crewRepository->save($crew1);
        $this->crewRepository->save($crew2);
        $this->crewRepository->save($crew3);
    }

    // ==================== GetMatchingDataUseCase Tests ====================

    public function testGetMatchingDataReturnsAvailableBoats(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertArrayHasKey('available_boats', $result);
        $this->assertCount(2, $result['available_boats']);
        $this->assertEquals('Boat One', $result['available_boats'][0]['display_name']);
        $this->assertEquals(4, $result['available_boats'][0]['berths']);
    }

    public function testGetMatchingDataReturnsAvailableCrews(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertArrayHasKey('available_crews', $result);
        $this->assertCount(3, $result['available_crews']);
        $this->assertEquals('Alice Smith', $result['available_crews'][0]['display_name']);
    }

    public function testGetMatchingDataCalculatesCapacity(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertArrayHasKey('capacity', $result);
        $this->assertEquals(9, $result['capacity']['total_berths']); // 4 + 5
        $this->assertEquals(3, $result['capacity']['total_crews']);
        $this->assertEquals(6, $result['capacity']['surplus_deficit']); // 9 - 3
    }

    public function testGetMatchingDataDeterminesTooFewCrewsScenario(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertEquals('too_few_crews', $result['capacity']['scenario']);
    }

    public function testGetMatchingDataDeterminesTooManyCrewsScenario(): void
    {
        // Add more crews to create "too many crews" scenario
        $eventId = EventId::fromString('Fri May 15');

        // Create 10 additional crews
        for ($i = 4; $i <= 13; $i++) {
            $user = new User("crew{$i}@example.com", 'hash', 'crew', false);
            $this->userRepository->save($user);

            $crew = new Crew(
                key: CrewKey::fromName("Crew{$i}", "Test"),
                displayName: "Crew{$i} Test",
                firstName: "Crew{$i}",
                lastName: "Test",
                partnerKey: null,
                mobile: null,
                socialPreference: false,
                membershipNumber: "M{$i}",
                skill: SkillLevel::INTERMEDIATE,
                experience: null
            );
            $crew->setUserId($user->getId());
            $crew->setAvailability($eventId, AvailabilityStatus::AVAILABLE);
            $this->crewRepository->save($crew);
        }

        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertEquals('too_many_crews', $result['capacity']['scenario']);
        $this->assertEquals(13, $result['capacity']['total_crews']);
    }

    public function testGetMatchingDataDeterminesPerfectFitScenario(): void
    {
        // Remove excess capacity by updating boat berths
        $eventId = EventId::fromString('Fri May 15');

        // Update berths to match crew count (2 + 1 = 3)
        $boat1 = $this->boatRepository->findByKey(BoatKey::fromString('Boat One'));
        $boat1->setBerths($eventId, 2);
        $this->boatRepository->save($boat1);

        $boat2 = $this->boatRepository->findByKey(BoatKey::fromString('Boat Two'));
        $boat2->setBerths($eventId, 1);
        $this->boatRepository->save($boat2);

        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertEquals('perfect_fit', $result['capacity']['scenario']);
        $this->assertEquals(3, $result['capacity']['total_berths']);
        $this->assertEquals(3, $result['capacity']['total_crews']);
    }

    public function testGetMatchingDataThrowsExceptionWhenEventNotFound(): void
    {
        $this->expectException(EventNotFoundException::class);

        $eventId = EventId::fromString('Non Existent Event');
        $this->getMatchingDataUseCase->execute($eventId->toString());
    }

    public function testGetMatchingDataReturnsEmptyWhenNoAvailableBoats(): void
    {
        // Mark all boats unavailable
        $eventId = EventId::fromString('Fri May 15');
        $boat1 = $this->boatRepository->findByKey(BoatKey::fromString('Boat One'));
        $boat2 = $this->boatRepository->findByKey(BoatKey::fromString('Boat Two'));

        $boat1->setBerths($eventId, 0);
        $boat2->setBerths($eventId, 0);

        $this->boatRepository->save($boat1);
        $this->boatRepository->save($boat2);

        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertEmpty($result['available_boats']);
        $this->assertEquals(0, $result['capacity']['total_berths']);
    }

    public function testGetMatchingDataReturnsEmptyWhenNoAvailableCrews(): void
    {
        // Mark all crews unavailable
        $eventId = EventId::fromString('Fri May 15');
        $crew1 = $this->crewRepository->findByKey(CrewKey::fromName('Alice', 'Smith'));
        $crew2 = $this->crewRepository->findByKey(CrewKey::fromName('Bob', 'Jones'));
        $crew3 = $this->crewRepository->findByKey(CrewKey::fromName('Charlie', 'Brown'));

        $crew1->setAvailability($eventId, AvailabilityStatus::UNAVAILABLE);
        $crew2->setAvailability($eventId, AvailabilityStatus::UNAVAILABLE);
        $crew3->setAvailability($eventId, AvailabilityStatus::UNAVAILABLE);

        $this->crewRepository->save($crew1);
        $this->crewRepository->save($crew2);
        $this->crewRepository->save($crew3);

        $result = $this->getMatchingDataUseCase->execute($eventId->toString());

        $this->assertEmpty($result['available_crews']);
        $this->assertEquals(0, $result['capacity']['total_crews']);
    }

}
