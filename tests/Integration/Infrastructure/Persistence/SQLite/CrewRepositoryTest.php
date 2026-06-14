<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence\SQLite;

use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for CrewRepository
 *
 * Tests database operations for crew including:
 * - CRUD operations
 * - Finding crew by various criteria
 * - Availability management
 * - History tracking
 * - Whitelist operations
 */
class CrewRepositoryTest extends IntegrationTestCase
{
    private CrewRepository $repository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CrewRepository();
        $this->userRepository = new UserRepository();
    }

    public function testSaveInsertsNewCrew(): void
    {
        $crew = new Crew(
            key: CrewKey::fromName('John', 'Sailor'),
            displayName: 'John Sailor',
            firstName: 'John',
            lastName: 'Sailor',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::INTERMEDIATE,
            experience: null
        );
        $this->repository->save($crew);

        $this->assertNotNull($crew->getId());
        $this->assertGreaterThan(0, $crew->getId());
    }

    public function testFindByKeyReturnsCrewWhenExists(): void
    {
        $key = CrewKey::fromName('Jane', 'Doe');
        $crew = $this->createTestCrew('Jane', 'Doe', SkillLevel::ADVANCED);
        $this->repository->save($crew);

        $foundCrew = $this->repository->findByKey($key);

        $this->assertNotNull($foundCrew);
        $this->assertEquals('Jane', $foundCrew->getFirstName());
        $this->assertEquals('Doe', $foundCrew->getLastName());
        $this->assertEquals(SkillLevel::ADVANCED, $foundCrew->getSkill());
    }

    public function testFindByKeyReturnsNullWhenNotExists(): void
    {
        $key = CrewKey::fromName('NonExistent', 'Person');

        $result = $this->repository->findByKey($key);

        $this->assertNull($result);
    }

    public function testFindByNameReturnsCrewWhenExists(): void
    {
        $crew = $this->createTestCrew('Alice', 'Wonder', SkillLevel::NOVICE);
        $this->repository->save($crew);

        $foundCrew = $this->repository->findByName('Alice', 'Wonder');

        $this->assertNotNull($foundCrew);
        $this->assertEquals('Alice Wonder', $foundCrew->getDisplayName());
    }

    public function testFindByNameReturnsNullWhenNotExists(): void
    {
        $result = $this->repository->findByName('Unknown', 'Person');

        $this->assertNull($result);
    }

    public function testFindByUserIdReturnsCrewWhenExists(): void
    {
        // Create user
        $user = new User(
            email: 'crewuser@example.com',
            passwordHash: 'hash',
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);
        $userId = $user->getId();

        // Create crew with user ID
        $crew = $this->createTestCrew('Bob', 'Builder', SkillLevel::INTERMEDIATE);
        $crew->setUserId($userId);
        $this->repository->save($crew);

        $foundCrew = $this->repository->findByUserId($userId);

        $this->assertNotNull($foundCrew);
        $this->assertEquals($userId, $foundCrew->getUserId());
        $this->assertEquals('Bob', $foundCrew->getFirstName());
    }

    public function testFindByUserIdReturnsNullWhenNotExists(): void
    {
        $result = $this->repository->findByUserId(99999);

        $this->assertNull($result);
    }

    public function testFindAllReturnsAllCrew(): void
    {
        $this->createAndSaveCrew('Crew1', 'LastName1', SkillLevel::NOVICE);
        $this->createAndSaveCrew('Crew2', 'LastName2', SkillLevel::INTERMEDIATE);
        $this->createAndSaveCrew('Crew3', 'LastName3', SkillLevel::ADVANCED);

        $crews = $this->repository->findAll();

        $this->assertGreaterThanOrEqual(3, count($crews));
        $displayNames = array_map(fn($c) => $c->getDisplayName(), $crews);
        $this->assertContains('Crew1 LastName1', $displayNames);
        $this->assertContains('Crew2 LastName2', $displayNames);
        $this->assertContains('Crew3 LastName3', $displayNames);
    }

    public function testFindAllReturnsCrewInAlphabeticalOrder(): void
    {
        $this->createAndSaveCrew('Zoe', 'Anderson', SkillLevel::NOVICE);
        $this->createAndSaveCrew('Alice', 'Brown', SkillLevel::INTERMEDIATE);
        $this->createAndSaveCrew('Bob', 'Carter', SkillLevel::ADVANCED);

        $crews = $this->repository->findAll();

        // Extract our test crews (there may be others from setup)
        $testCrews = array_filter($crews, function($c) {
            return in_array($c->getDisplayName(), ['Alice Brown', 'Bob Carter', 'Zoe Anderson']);
        });
        $testCrews = array_values($testCrews);

        $this->assertEquals('Alice Brown', $testCrews[0]->getDisplayName());
        $this->assertEquals('Bob Carter', $testCrews[1]->getDisplayName());
        $this->assertEquals('Zoe Anderson', $testCrews[2]->getDisplayName());
    }

    public function testFindAvailableForEventReturnsOnlyAvailableCrew(): void
    {
        $eventId = EventId::fromString('2026-03-20');
        $this->createTestEvent('2026-03-20', '2026-03-20');

        // Create crew with different availability statuses
        $crew1 = $this->createAndSaveCrew('Available1', 'Test', SkillLevel::NOVICE);
        $crew2 = $this->createAndSaveCrew('Available2', 'Test', SkillLevel::INTERMEDIATE);
        $crew3 = $this->createAndSaveCrew('Unavailable', 'Test', SkillLevel::ADVANCED);
        $crew4 = $this->createAndSaveCrew('Guaranteed', 'Test', SkillLevel::INTERMEDIATE);

        // Set availability (1=AVAILABLE, 2=GUARANTEED, 0=UNAVAILABLE)
        $this->repository->updateAvailability($crew1->getKey(), $eventId, AvailabilityStatus::AVAILABLE);
        $this->repository->updateAvailability($crew2->getKey(), $eventId, AvailabilityStatus::AVAILABLE);
        $this->repository->updateAvailability($crew3->getKey(), $eventId, AvailabilityStatus::UNAVAILABLE);
        $this->repository->updateAvailability($crew4->getKey(), $eventId, AvailabilityStatus::GUARANTEED);

        $availableCrew = $this->repository->findAvailableForEvent($eventId);

        $displayNames = array_map(fn($c) => $c->getDisplayName(), $availableCrew);
        $this->assertContains('Available1 Test', $displayNames);
        $this->assertContains('Available2 Test', $displayNames);
        $this->assertContains('Guaranteed Test', $displayNames);
        $this->assertNotContains('Unavailable Test', $displayNames);
    }

    public function testFindAllExcludesCrewLinkedToDisabledUser(): void
    {
        // Active crew (no linked user)
        $this->createAndSaveCrew('Active', 'Member', SkillLevel::NOVICE);

        // Crew linked to a disabled user
        $user = new User(
            email: 'suspended-crew@example.com',
            passwordHash: 'hash',
            accountType: 'crew',
            isAdmin: false
        );
        $user->disable(new \DateTimeImmutable());
        $this->userRepository->save($user);

        $disabledCrew = $this->createTestCrew('Suspended', 'Member', SkillLevel::ADVANCED);
        $disabledCrew->setUserId($user->getId());
        $this->repository->save($disabledCrew);

        $crews = $this->repository->findAll();
        $displayNames = array_map(fn($c) => $c->getDisplayName(), $crews);

        $this->assertContains('Active Member', $displayNames);
        $this->assertNotContains('Suspended Member', $displayNames);

        // Direct lookup still returns the crew (admin/profile views must still work)
        $this->assertNotNull($this->repository->findByKey($disabledCrew->getKey()));
    }

    public function testFindAvailableForEventExcludesCrewLinkedToDisabledUser(): void
    {
        $eventId = EventId::fromString('2026-04-10');
        $this->createTestEvent('2026-04-10', '2026-04-10');

        $user = new User(
            email: 'suspended-available@example.com',
            passwordHash: 'hash',
            accountType: 'crew',
            isAdmin: false
        );
        $user->disable(new \DateTimeImmutable());
        $this->userRepository->save($user);

        $crew = $this->createTestCrew('SuspendedAvail', 'Member', SkillLevel::INTERMEDIATE);
        $crew->setUserId($user->getId());
        $this->repository->save($crew);

        // Even though available, a suspended account must not be selectable
        $this->repository->updateAvailability($crew->getKey(), $eventId, AvailabilityStatus::AVAILABLE);

        $available = $this->repository->findAvailableForEvent($eventId);
        $displayNames = array_map(fn($c) => $c->getDisplayName(), $available);

        $this->assertNotContains('SuspendedAvail Member', $displayNames);
    }

    public function testFindAssignedToEventReturnsOnlyGuaranteedCrew(): void
    {
        $eventId = EventId::fromString('2026-03-25');
        $this->createTestEvent('2026-03-25', '2026-03-25');

        $crew1 = $this->createAndSaveCrew('Assigned1', 'Test', SkillLevel::NOVICE);
        $crew2 = $this->createAndSaveCrew('Assigned2', 'Test', SkillLevel::INTERMEDIATE);
        $crew3 = $this->createAndSaveCrew('NotAssigned', 'Test', SkillLevel::ADVANCED);

        $this->repository->updateAvailability($crew1->getKey(), $eventId, AvailabilityStatus::GUARANTEED);
        $this->repository->updateAvailability($crew2->getKey(), $eventId, AvailabilityStatus::GUARANTEED);
        $this->repository->updateAvailability($crew3->getKey(), $eventId, AvailabilityStatus::AVAILABLE);

        $assignedCrew = $this->repository->findAssignedToEvent($eventId);

        $this->assertCount(2, $assignedCrew);
        $displayNames = array_map(fn($c) => $c->getDisplayName(), $assignedCrew);
        $this->assertContains('Assigned1 Test', $displayNames);
        $this->assertContains('Assigned2 Test', $displayNames);
        $this->assertNotContains('NotAssigned Test', $displayNames);
    }

    public function testSaveUpdatesExistingCrew(): void
    {
        $crew = $this->createTestCrew('Charlie', 'Update', SkillLevel::NOVICE);
        $this->repository->save($crew);
        $crewId = $crew->getId();

        // Update skill level
        $crew->setSkill(SkillLevel::ADVANCED);
        $this->repository->save($crew);

        $updatedCrew = $this->repository->findByKey($crew->getKey());
        $this->assertNotNull($updatedCrew);
        $this->assertEquals(SkillLevel::ADVANCED, $updatedCrew->getSkill());
        $this->assertEquals($crewId, $updatedCrew->getId());
    }

    public function testDeleteRemovesCrew(): void
    {
        $crew = $this->createTestCrew('Delete', 'Me', SkillLevel::NOVICE);
        $this->repository->save($crew);
        $key = $crew->getKey();

        $this->assertTrue($this->repository->exists($key));

        $this->repository->delete($key);

        $this->assertFalse($this->repository->exists($key));
        $this->assertNull($this->repository->findByKey($key));
    }

    public function testDeleteNonExistentCrewDoesNotThrowError(): void
    {
        $key = CrewKey::fromName('NonExistent', 'Crew');

        $this->repository->delete($key);

        $this->assertFalse($this->repository->exists($key));
    }

    public function testExistsReturnsTrueForExistingCrew(): void
    {
        $crew = $this->createTestCrew('Exists', 'Test', SkillLevel::INTERMEDIATE);
        $this->repository->save($crew);

        $this->assertTrue($this->repository->exists($crew->getKey()));
    }

    public function testExistsReturnsFalseForNonExistentCrew(): void
    {
        $key = CrewKey::fromName('NoSuch', 'Crew');

        $this->assertFalse($this->repository->exists($key));
    }

    public function testUpdateAvailabilityCreatesAvailabilityRecord(): void
    {
        $eventId = EventId::fromString('2026-04-10');
        $this->createTestEvent('2026-04-10', '2026-04-10');

        $crew = $this->createAndSaveCrew('Avail', 'Test', SkillLevel::INTERMEDIATE);

        $this->repository->updateAvailability($crew->getKey(), $eventId, AvailabilityStatus::AVAILABLE);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $foundCrew->getAvailability($eventId));
    }

    public function testUpdateAvailabilityUpdatesExistingRecord(): void
    {
        $eventId = EventId::fromString('2026-04-15');
        $this->createTestEvent('2026-04-15', '2026-04-15');

        $crew = $this->createAndSaveCrew('Update', 'Avail', SkillLevel::ADVANCED);

        // Set initial availability
        $this->repository->updateAvailability($crew->getKey(), $eventId, AvailabilityStatus::AVAILABLE);

        // Update to guaranteed
        $this->repository->updateAvailability($crew->getKey(), $eventId, AvailabilityStatus::GUARANTEED);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertEquals(AvailabilityStatus::GUARANTEED, $foundCrew->getAvailability($eventId));
    }

    public function testUpdateAvailabilityThrowsExceptionForNonExistentCrew(): void
    {
        $eventId = EventId::fromString('2026-04-20');
        $this->createTestEvent('2026-04-20', '2026-04-20');

        $key = CrewKey::fromName('NonExistent', 'Crew');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Crew not found');

        $this->repository->updateAvailability($key, $eventId, AvailabilityStatus::AVAILABLE);
    }

    public function testUpdateHistoryCreatesHistoryRecord(): void
    {
        $eventId = EventId::fromString('2026-05-01');
        $this->createTestEvent('2026-05-01', '2026-05-01');

        $crew = $this->createAndSaveCrew('History', 'Test', SkillLevel::NOVICE);

        $this->repository->updateHistory($crew->getKey(), $eventId, 'smith-john');

        // Verify history was recorded
        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertNotNull($foundCrew);
    }

    public function testUpdateHistoryThrowsExceptionForNonExistentCrew(): void
    {
        $eventId = EventId::fromString('2026-05-05');
        $this->createTestEvent('2026-05-05', '2026-05-05');

        $key = CrewKey::fromName('NonExistent', 'Crew');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Crew not found');

        $this->repository->updateHistory($key, $eventId, 'some-boat');
    }

    public function testAddToWhitelistAddsBoatToWhitelist(): void
    {
        $crew = $this->createAndSaveCrew('Whitelist', 'Test', SkillLevel::INTERMEDIATE);
        $boatKey = BoatKey::fromBoatName('Boat Owner');

        $this->repository->addToWhitelist($crew->getKey(), $boatKey);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertTrue($foundCrew->isWhitelisted($boatKey));
    }

    public function testAddToWhitelistThrowsExceptionForNonExistentCrew(): void
    {
        $crewKey = CrewKey::fromName('NonExistent', 'Crew');
        $boatKey = BoatKey::fromBoatName('Boat Owner');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Crew not found');

        $this->repository->addToWhitelist($crewKey, $boatKey);
    }

    public function testRemoveFromWhitelistRemovesBoatFromWhitelist(): void
    {
        $crew = $this->createAndSaveCrew('Remove', 'Whitelist', SkillLevel::ADVANCED);
        $boatKey = BoatKey::fromBoatName('TestBoat Owner');

        // Add to whitelist first
        $this->repository->addToWhitelist($crew->getKey(), $boatKey);
        $this->assertTrue($this->repository->findByKey($crew->getKey())->isWhitelisted($boatKey));

        // Remove from whitelist
        $this->repository->removeFromWhitelist($crew->getKey(), $boatKey);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertFalse($foundCrew->isWhitelisted($boatKey));
    }

    public function testRemoveFromWhitelistThrowsExceptionForNonExistentCrew(): void
    {
        $crewKey = CrewKey::fromName('NonExistent', 'Crew');
        $boatKey = BoatKey::fromBoatName('Boat Owner');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Crew not found');

        $this->repository->removeFromWhitelist($crewKey, $boatKey);
    }

    public function testAddToWhitelistIsIdempotent(): void
    {
        $crew = $this->createAndSaveCrew('Idempotent', 'Test', SkillLevel::INTERMEDIATE);
        $boatKey = BoatKey::fromBoatName('Boat Owner');

        // Add multiple times
        $this->repository->addToWhitelist($crew->getKey(), $boatKey);
        $this->repository->addToWhitelist($crew->getKey(), $boatKey);
        $this->repository->addToWhitelist($crew->getKey(), $boatKey);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertTrue($foundCrew->isWhitelisted($boatKey));
    }

    public function testCountReturnsCorrectNumberOfCrew(): void
    {
        $initialCount = $this->repository->count();

        $this->createAndSaveCrew('Count1', 'Test', SkillLevel::NOVICE);
        $this->createAndSaveCrew('Count2', 'Test', SkillLevel::INTERMEDIATE);
        $this->createAndSaveCrew('Count3', 'Test', SkillLevel::ADVANCED);

        $this->assertEquals($initialCount + 3, $this->repository->count());
    }

    public function testCrewPropertiesArePersisted(): void
    {
        $crew = new Crew(
            key: CrewKey::fromName('Props', 'Test'),
            displayName: 'Props Test',
            firstName: 'Props',
            lastName: 'Test',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::ADVANCED,
            experience: null
        );
        $crew->setMobile('555-9999');
        $crew->setSocialPreference(true);
        $crew->setMembershipNumber('MEM-123');
        $crew->setExperience('Special notes');

        $this->repository->save($crew);

        $foundCrew = $this->repository->findByKey($crew->getKey());

        $this->assertNotNull($foundCrew);
        $this->assertEquals('Props Test', $foundCrew->getDisplayName());
        $this->assertEquals('Props', $foundCrew->getFirstName());
        $this->assertEquals('Test', $foundCrew->getLastName());
        $this->assertEquals(SkillLevel::ADVANCED, $foundCrew->getSkill());
        $this->assertEquals('555-9999', $foundCrew->getMobile());
        $this->assertTrue($foundCrew->hasSocialPreference());
        $this->assertEquals('MEM-123', $foundCrew->getMembershipNumber());
        $this->assertEquals('Special notes', $foundCrew->getExperience());
    }

    public function testSkillLevelAllVariantsArePersisted(): void
    {
        $novice = $this->createTestCrew('Novice', 'Test', SkillLevel::NOVICE);
        $intermediate = $this->createTestCrew('Intermediate', 'Test', SkillLevel::INTERMEDIATE);
        $advanced = $this->createTestCrew('Advanced', 'Test', SkillLevel::ADVANCED);

        $this->repository->save($novice);
        $this->repository->save($intermediate);
        $this->repository->save($advanced);

        $this->assertEquals(SkillLevel::NOVICE, $this->repository->findByKey($novice->getKey())->getSkill());
        $this->assertEquals(SkillLevel::INTERMEDIATE, $this->repository->findByKey($intermediate->getKey())->getSkill());
        $this->assertEquals(SkillLevel::ADVANCED, $this->repository->findByKey($advanced->getKey())->getSkill());
    }

    public function testAvailabilityCanBeSetIndependentlyForDifferentEvents(): void
    {
        $event1 = EventId::fromString('2026-06-01');
        $event2 = EventId::fromString('2026-06-15');
        $this->createTestEvent('2026-06-01', '2026-06-01');
        $this->createTestEvent('2026-06-15', '2026-06-15');

        $crew = $this->createAndSaveCrew('Multi', 'Event', SkillLevel::INTERMEDIATE);

        $this->repository->updateAvailability($crew->getKey(), $event1, AvailabilityStatus::AVAILABLE);
        $this->repository->updateAvailability($crew->getKey(), $event2, AvailabilityStatus::UNAVAILABLE);

        $foundCrew = $this->repository->findByKey($crew->getKey());
        $this->assertEquals(AvailabilityStatus::AVAILABLE, $foundCrew->getAvailability($event1));
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE, $foundCrew->getAvailability($event2));
    }

    // Helper methods
    private function createTestCrew(string $firstName, string $lastName, SkillLevel $skill): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromName($firstName, $lastName),
            displayName: "$firstName $lastName",
            firstName: $firstName,
            lastName: $lastName,
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: $skill,
            experience: null
        );
        return $crew;
    }

    private function createAndSaveCrew(string $firstName, string $lastName, SkillLevel $skill): Crew
    {
        $crew = $this->createTestCrew($firstName, $lastName, $skill);
        $this->repository->save($crew);
        return $crew;
    }
}
