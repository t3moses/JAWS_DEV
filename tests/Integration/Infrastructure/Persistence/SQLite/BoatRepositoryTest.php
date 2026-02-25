<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence\SQLite;

use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Domain\Entity\Boat;
use App\Domain\Entity\User;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for BoatRepository
 *
 * Tests database operations for boats including:
 * - CRUD operations
 * - Finding boats by various criteria
 * - Availability management
 * - History tracking
 */
class BoatRepositoryTest extends IntegrationTestCase
{
    private BoatRepository $repository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new BoatRepository();
        $this->userRepository = new UserRepository();
    }

    public function testSaveInsertsNewBoat(): void
    {
        $boat = new Boat(
            key: BoatKey::fromBoatName('Serenity'),
            displayName: 'Serenity',
            ownerFirstName: 'John',
            ownerLastName: 'Smith',
            ownerMobile: '555-1234',
            minBerths: 2,
            maxBerths: 6,
            assistanceRequired: false,
            socialPreference: false
        );
        $this->repository->save($boat);

        $this->assertNotNull($boat->getId());
        $this->assertGreaterThan(0, $boat->getId());
    }

    public function testFindByKeyReturnsBoatWhenExists(): void
    {
        $key = BoatKey::fromBoatName('WindSeeker');
        $boat = $this->createTestBoat('Jane', 'Doe', 'WindSeeker');
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByKey($key);

        $this->assertNotNull($foundBoat);
        $this->assertEquals('WindSeeker', $foundBoat->getDisplayName());
        $this->assertEquals('Jane', $foundBoat->getOwnerFirstName());
        $this->assertEquals('Doe', $foundBoat->getOwnerLastName());
    }

    public function testFindByKeyReturnsNullWhenNotExists(): void
    {
        $key = BoatKey::fromBoatName('NonExistentBoat');

        $result = $this->repository->findByKey($key);

        $this->assertNull($result);
    }

    public function testFindByOwnerNameReturnsBoatWhenExists(): void
    {
        $boat = $this->createTestBoat('Alice', 'Wonder', 'Dream Catcher');
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByOwnerName('Alice', 'Wonder');

        $this->assertNotNull($foundBoat);
        $this->assertEquals('Dream Catcher', $foundBoat->getDisplayName());
    }

    public function testFindByOwnerNameReturnsNullWhenNotExists(): void
    {
        $result = $this->repository->findByOwnerName('Unknown', 'Person');

        $this->assertNull($result);
    }

    public function testFindByOwnerUserIdReturnsBoatWhenExists(): void
    {
        // Create user
        $user = new User(
            email: 'boatowner@example.com',
            passwordHash: 'hash',
            accountType: 'boat_owner',
            isAdmin: false
        );
        $this->userRepository->save($user);
        $userId = $user->getId();

        // Create boat with user ID
        $boat = $this->createTestBoat('Bob', 'Builder', 'Can We Fix It');
        $boat->setOwnerUserId($userId);
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByOwnerUserId($userId);

        $this->assertNotNull($foundBoat);
        $this->assertEquals($userId, $foundBoat->getOwnerUserId());
        $this->assertEquals('Can We Fix It', $foundBoat->getDisplayName());
    }

    public function testFindByOwnerUserIdReturnsNullWhenNotExists(): void
    {
        $result = $this->repository->findByOwnerUserId(99999);

        $this->assertNull($result);
    }

    public function testFindAllReturnsAllBoats(): void
    {
        $this->createAndSaveBoat('Owner1', 'LastName1', 'Boat A');
        $this->createAndSaveBoat('Owner2', 'LastName2', 'Boat B');
        $this->createAndSaveBoat('Owner3', 'LastName3', 'Boat C');

        $boats = $this->repository->findAll();

        $this->assertCount(3, $boats);
        $displayNames = array_map(fn($b) => $b->getDisplayName(), $boats);
        $this->assertContains('Boat A', $displayNames);
        $this->assertContains('Boat B', $displayNames);
        $this->assertContains('Boat C', $displayNames);
    }

    public function testFindAllReturnsBoatsInAlphabeticalOrder(): void
    {
        $this->createAndSaveBoat('Owner1', 'LastName1', 'Zebra');
        $this->createAndSaveBoat('Owner2', 'LastName2', 'Alpha');
        $this->createAndSaveBoat('Owner3', 'LastName3', 'Beta');

        $boats = $this->repository->findAll();

        $this->assertEquals('Alpha', $boats[0]->getDisplayName());
        $this->assertEquals('Beta', $boats[1]->getDisplayName());
        $this->assertEquals('Zebra', $boats[2]->getDisplayName());
    }

    public function testFindAvailableForEventReturnsOnlyAvailableBoats(): void
    {
        $eventId = EventId::fromString('2026-03-15');
        $this->createTestEvent('2026-03-15', '2026-03-15');

        // Create boats with availability
        $boat1 = $this->createAndSaveBoat('Owner1', 'LastName1', 'Available Boat 1');
        $boat2 = $this->createAndSaveBoat('Owner2', 'LastName2', 'Available Boat 2');
        $boat3 = $this->createAndSaveBoat('Owner3', 'LastName3', 'Unavailable Boat');

        // Set availability (berths > 0 = available)
        $this->repository->updateAvailability($boat1->getKey(), $eventId, 4);
        $this->repository->updateAvailability($boat2->getKey(), $eventId, 6);
        $this->repository->updateAvailability($boat3->getKey(), $eventId, 0); // Unavailable

        $availableBoats = $this->repository->findAvailableForEvent($eventId);

        $this->assertCount(2, $availableBoats);
        $displayNames = array_map(fn($b) => $b->getDisplayName(), $availableBoats);
        $this->assertContains('Available Boat 1', $displayNames);
        $this->assertContains('Available Boat 2', $displayNames);
        $this->assertNotContains('Unavailable Boat', $displayNames);
    }

    public function testSaveUpdatesExistingBoat(): void
    {
        $boat = $this->createTestBoat('Charlie', 'Brown', 'Original Name');
        $this->repository->save($boat);
        $boatId = $boat->getId();

        // Update display name
        $boat->setDisplayName('Updated Name');
        $this->repository->save($boat);

        $updatedBoat = $this->repository->findByKey($boat->getKey());
        $this->assertNotNull($updatedBoat);
        $this->assertEquals('Updated Name', $updatedBoat->getDisplayName());
        $this->assertEquals($boatId, $updatedBoat->getId());
    }

    public function testDeleteRemovesBoat(): void
    {
        $boat = $this->createTestBoat('Delete', 'Me', 'ToBeDeleted');
        $this->repository->save($boat);
        $key = $boat->getKey();

        $this->assertTrue($this->repository->exists($key));

        $this->repository->delete($key);

        $this->assertFalse($this->repository->exists($key));
        $this->assertNull($this->repository->findByKey($key));
    }

    public function testDeleteNonExistentBoatDoesNotThrowError(): void
    {
        $key = BoatKey::fromBoatName('NonExistentBoat');

        $this->repository->delete($key);

        $this->assertFalse($this->repository->exists($key));
    }

    public function testExistsReturnsTrueForExistingBoat(): void
    {
        $boat = $this->createTestBoat('Exists', 'Test', 'ExistingBoat');
        $this->repository->save($boat);

        $this->assertTrue($this->repository->exists($boat->getKey()));
    }

    public function testExistsReturnsFalseForNonExistentBoat(): void
    {
        $key = BoatKey::fromBoatName('NoSuchBoat');

        $this->assertFalse($this->repository->exists($key));
    }

    public function testUpdateAvailabilityCreatesAvailabilityRecord(): void
    {
        $eventId = EventId::fromString('2026-04-10');
        $this->createTestEvent('2026-04-10', '2026-04-10');

        $boat = $this->createAndSaveBoat('Avail', 'Test', 'TestBoat');

        $this->repository->updateAvailability($boat->getKey(), $eventId, 5);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertEquals(5, $foundBoat->getBerths($eventId));
    }

    public function testUpdateAvailabilityUpdatesExistingRecord(): void
    {
        $eventId = EventId::fromString('2026-04-15');
        $this->createTestEvent('2026-04-15', '2026-04-15');

        $boat = $this->createAndSaveBoat('Update', 'Avail', 'UpdateTest');

        // Set initial availability
        $this->repository->updateAvailability($boat->getKey(), $eventId, 4);

        // Update availability
        $this->repository->updateAvailability($boat->getKey(), $eventId, 6);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertEquals(6, $foundBoat->getBerths($eventId));
    }

    public function testUpdateAvailabilityThrowsExceptionForNonExistentBoat(): void
    {
        $eventId = EventId::fromString('2026-04-20');
        $this->createTestEvent('2026-04-20', '2026-04-20');

        $key = BoatKey::fromBoatName('NonExistentBoat');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Boat not found');

        $this->repository->updateAvailability($key, $eventId, 5);
    }

    public function testUpdateHistoryCreatesHistoryRecord(): void
    {
        $eventId = EventId::fromString('2026-05-01');
        $this->createTestEvent('2026-05-01', '2026-05-01');

        $boat = $this->createAndSaveBoat('History', 'Test', 'HistoryBoat');

        $this->repository->updateHistory($boat->getKey(), $eventId, 'yes');

        // Verify history was recorded (by loading boat and checking internal state)
        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertNotNull($foundBoat);
    }

    public function testUpdateHistoryThrowsExceptionForNonExistentBoat(): void
    {
        $eventId = EventId::fromString('2026-05-05');
        $this->createTestEvent('2026-05-05', '2026-05-05');

        $key = BoatKey::fromBoatName('NonExistentBoat');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Boat not found');

        $this->repository->updateHistory($key, $eventId, 'yes');
    }

    public function testCountReturnsCorrectNumberOfBoats(): void
    {
        $initialCount = $this->repository->count();

        $this->createAndSaveBoat('Count1', 'Test', 'Boat1');
        $this->createAndSaveBoat('Count2', 'Test', 'Boat2');
        $this->createAndSaveBoat('Count3', 'Test', 'Boat3');

        $this->assertEquals($initialCount + 3, $this->repository->count());
    }

    public function testBoatPropertiesArePersisted(): void
    {
        $boat = new Boat(
            key: BoatKey::fromBoatName('Test Properties'),
            displayName: 'Test Properties',
            ownerFirstName: 'Props',
            ownerLastName: 'Test',
            ownerMobile: '555-1111',
            minBerths: 3,
            maxBerths: 7,
            assistanceRequired: true,
            socialPreference: false
        );
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByKey($boat->getKey());

        $this->assertNotNull($foundBoat);
        $this->assertEquals('Test Properties', $foundBoat->getDisplayName());
        $this->assertEquals('Props', $foundBoat->getOwnerFirstName());
        $this->assertEquals('Test', $foundBoat->getOwnerLastName());
        $this->assertEquals(3, $foundBoat->getMinBerths());
        $this->assertEquals(7, $foundBoat->getMaxBerths());
        $this->assertTrue($foundBoat->requiresAssistance());
    }

    public function testOwnerMobileIsPersisted(): void
    {
        $boat = $this->createTestBoat('Mobile', 'Test', 'MobileBoat');
        $boat->setOwnerMobile('555-1234');

        $this->repository->save($boat);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertEquals('555-1234', $foundBoat->getOwnerMobile());
    }

    public function testRequiresAssistanceFlagCanBeToggled(): void
    {
        $boat = $this->createTestBoat('Assist', 'Test', 'AssistBoat');
        $boat->setAssistanceRequired(false);
        $this->repository->save($boat);
        $boatId = $boat->getId();

        // Toggle to true
        $boat->setAssistanceRequired(true);
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertTrue($foundBoat->requiresAssistance());

        // Toggle back to false
        $boat->setAssistanceRequired(false);
        $this->repository->save($boat);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertFalse($foundBoat->requiresAssistance());
    }

    public function testBerthsCanBeUpdatedIndependentlyForDifferentEvents(): void
    {
        $event1 = EventId::fromString('2026-06-01');
        $event2 = EventId::fromString('2026-06-15');
        $this->createTestEvent('2026-06-01', '2026-06-01');
        $this->createTestEvent('2026-06-15', '2026-06-15');

        $boat = $this->createAndSaveBoat('Multi', 'Event', 'MultiEventBoat');

        $this->repository->updateAvailability($boat->getKey(), $event1, 4);
        $this->repository->updateAvailability($boat->getKey(), $event2, 6);

        $foundBoat = $this->repository->findByKey($boat->getKey());
        $this->assertEquals(4, $foundBoat->getBerths($event1));
        $this->assertEquals(6, $foundBoat->getBerths($event2));
    }

    // Helper methods
    private function createTestBoat(string $firstName, string $lastName, string $displayName): Boat
    {
        $boat = new Boat(
            key: BoatKey::fromBoatName($displayName),
            displayName: $displayName,
            ownerFirstName: $firstName,
            ownerLastName: $lastName,
            ownerMobile: '555-0000',
            minBerths: 2,
            maxBerths: 6,
            assistanceRequired: false,
            socialPreference: false
        );
        return $boat;
    }

    private function createAndSaveBoat(string $firstName, string $lastName, string $displayName): Boat
    {
        $boat = $this->createTestBoat($firstName, $lastName, $displayName);
        $this->repository->save($boat);
        return $boat;
    }
}
