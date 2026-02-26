<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Crew;

use App\Application\DTO\Request\UpdateAvailabilityRequest;
use App\Application\Exception\ValidationException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\UseCase\Crew\UpdateCrewAvailabilityUseCase;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\CrewKey;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Persistence\SQLite\Connection;
use App\Infrastructure\Service\SystemTimeService;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for UpdateCrewAvailabilityUseCase
 *
 * Tests the complete crew availability update workflow including:
 * - Single and multiple event availability updates
 * - Status mapping (boolean to enum)
 * - Validation scenarios
 * - Edge cases and error conditions
 */
class UpdateCrewAvailabilityUseCaseTest extends IntegrationTestCase
{
    private UpdateCrewAvailabilityUseCase $useCase;
    private CrewRepository $crewRepository;
    private EventRepository $eventRepository;

    protected function setUp(): void
    {
        parent::setUp();  // Runs migrations, initializes season config, sets test connection

        // Initialize test data (events)
        $this->initializeTestData();

        // Initialize repositories
        $this->crewRepository = new CrewRepository();
        $this->eventRepository = new EventRepository();

        // Initialize use case
        $this->useCase = new UpdateCrewAvailabilityUseCase(
            $this->crewRepository,
            $this->eventRepository,
            new SystemTimeService(),
            new SeasonRepository()
        );
    }

    // ==================== HELPER METHODS ====================

    /**
     * Initialize test data (events)
     */
    protected function initializeTestData(): void
    {
        // Insert test events
        $events = [
            ['Fri May 15', '2026-05-15', '12:45:00', '17:00:00', 'upcoming'],
            ['Fri May 22', '2026-05-22', '12:45:00', '17:00:00', 'upcoming'],
            ['Fri May 29', '2026-05-29', '12:45:00', '17:00:00', 'upcoming'],
            ['Fri Jun 05', '2026-06-05', '12:45:00', '17:00:00', 'upcoming'],
        ];

        foreach ($events as $event) {
            $this->pdo->exec("
                INSERT INTO events (event_id, event_date, start_time, finish_time, status)
                VALUES ('{$event[0]}', '{$event[1]}', '{$event[2]}', '{$event[3]}', '{$event[4]}')
            ");
        }
    }

    /**
     * Create crew profile for user
     */
    protected function createCrewProfileForUser(int $userId, array $overrides = []): string
    {
        $key = $overrides['key'] ?? 'crew_' . $userId;

        $stmt = $this->pdo->prepare('
            INSERT INTO crews (
                key, display_name, first_name, last_name, skill, mobile,
                membership_number, social_preference, experience,
                user_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');

        $stmt->execute([
            $key,
            $overrides['displayName'] ?? 'Test Crew',
            $overrides['firstName'] ?? 'Test',
            $overrides['lastName'] ?? 'Crew',
            $overrides['skill'] ?? SkillLevel::INTERMEDIATE->value,
            $overrides['mobile'] ?? '555-1234',
            $overrides['membershipNumber'] ?? '12345',
            $overrides['socialPreference'] ?? 'No',
            $overrides['experience'] ?? 'Some sailing experience',
            $userId
        ]);

        return $key;
    }

    /**
     * Get crew availability from database
     */
    protected function getCrewAvailability(string $crewKey, string $eventId): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT ca.status FROM crew_availability ca
            JOIN crews c ON ca.crew_id = c.id
            WHERE c.key = ? AND ca.event_id = ?
        ');
        $stmt->execute([$crewKey, $eventId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
    }

    // ==================== HAPPY PATH TESTS ====================

    public function testUpdateAvailabilityForSingleEvent(): void
    {
        // Arrange
        $userId = $this->createTestUser('crew1@example.com');
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert
        $this->assertEquals($crewKey, $response->key);
        $this->assertArrayHasKey('Fri May 15', $response->availabilities);
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response->availabilities['Fri May 15']);

        // Verify database
        $status = $this->getCrewAvailability($crewKey, 'Fri May 15');
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $status);
    }

    public function testUpdateAvailabilityForMultipleEvents(): void
    {
        // Arrange
        $userId = $this->createTestUser('crew2@example.com');
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true],
            ['eventId' => 'Fri May 22', 'isAvailable' => false],
            ['eventId' => 'Fri May 29', 'isAvailable' => true],
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response->availabilities['Fri May 15']);
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $response->availabilities['Fri May 22']);
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response->availabilities['Fri May 29']);

        // Verify database
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $this->getCrewAvailability($crewKey, 'Fri May 15'));
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $this->getCrewAvailability($crewKey, 'Fri May 22'));
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $this->getCrewAvailability($crewKey, 'Fri May 29'));
    }

    public function testUpdateAvailabilityToAvailable(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response->availabilities['Fri May 15']);
    }

    public function testUpdateAvailabilityToUnavailable(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => false]
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $response->availabilities['Fri May 15']);
    }

    public function testUpdateSameEventMultipleTimes(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        // First update - available
        $request1 = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);
        $response1 = $this->useCase->execute($userId, $request1);
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response1->availabilities['Fri May 15']);

        // Second update - unavailable
        $request2 = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => false]
        ]);
        $response2 = $this->useCase->execute($userId, $request2);
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $response2->availabilities['Fri May 15']);

        // Third update - available again
        $request3 = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);
        $response3 = $this->useCase->execute($userId, $request3);
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response3->availabilities['Fri May 15']);

        // Verify final state in database
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $this->getCrewAvailability($crewKey, 'Fri May 15'));
    }

    public function testUpdateAllSeasonEvents(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true],
            ['eventId' => 'Fri May 22', 'isAvailable' => true],
            ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ['eventId' => 'Fri Jun 05', 'isAvailable' => true],
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert - all events should be available
        foreach (['Fri May 15', 'Fri May 22', 'Fri May 29', 'Fri Jun 05'] as $eventId) {
            $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response->availabilities[$eventId]);
            $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $this->getCrewAvailability($crewKey, $eventId));
        }
    }

    // ==================== VALIDATION TESTS ====================

    public function testValidationErrorForInvalidDataStructure(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        // Invalid: not an array of objects
        $request = new UpdateAvailabilityRequest([
            'invalid_structure'
        ]);

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Each availability must be an object');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testValidationErrorForMissingEventId(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['isAvailable' => true] // Missing eventId
        ]);

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Event ID is required');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testValidationErrorForMissingIsAvailable(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15'] // Missing isAvailable
        ]);

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('isAvailable is required');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testValidationErrorForNonBooleanIsAvailable(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => 'yes'] // String instead of boolean
        ]);

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be a boolean');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testValidationErrorForNonStringEventId(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 123, 'isAvailable' => true] // Integer instead of string
        ]);

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be a string');

        // Act
        $this->useCase->execute($userId, $request);
    }

    // ==================== ERROR CONDITION TESTS ====================

    public function testCrewNotFoundThrowsException(): void
    {
        // Arrange
        $nonExistentUserId = 99999;

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Assert
        $this->expectException(CrewNotFoundException::class);
        $this->expectExceptionMessage('Crew not found for user ID: 99999');

        // Act
        $this->useCase->execute($nonExistentUserId, $request);
    }

    public function testEventNotFoundThrowsException(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri Jul 99', 'isAvailable' => true] // Non-existent event
        ]);

        // Assert
        $this->expectException(EventNotFoundException::class);
        $this->expectExceptionMessage('Event not found');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testUserWithoutCrewProfileThrowsException(): void
    {
        // Arrange
        $userId = $this->createTestUser('nocrew@example.com');
        // Don't create crew profile

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Assert
        $this->expectException(CrewNotFoundException::class);

        // Act
        $this->useCase->execute($userId, $request);
    }

    // ==================== EDGE CASE TESTS ====================

    public function testEmptyAvailabilityArrayIsValid(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        // Empty array is allowed (validation is currently commented out in the DTO)
        $request = new UpdateAvailabilityRequest([]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert - should succeed without updating anything
        $this->assertEquals($crewKey, $response->key);
    }

    public function testPartialValidationErrorsInBatch(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $this->createCrewProfileForUser($userId);

        // One valid, one invalid
        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true], // Valid
            ['eventId' => 'Fri May 22'] // Invalid - missing isAvailable
        ]);

        // Assert - should fail validation before any database updates
        $this->expectException(ValidationException::class);

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testMixedEventValidityInBatch(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        // First event valid, second invalid
        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true], // Valid event
            ['eventId' => 'Invalid Event', 'isAvailable' => true] // Invalid event
        ]);

        // Assert - should fail on second event
        $this->expectException(EventNotFoundException::class);
        $this->expectExceptionMessage('Event not found');

        // Act
        $this->useCase->execute($userId, $request);
    }

    public function testPreservesExistingCrewData(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId, [
            'displayName' => 'Original Name',
            'mobile' => '555-1234',
            'skill' => SkillLevel::ADVANCED->value
        ]);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert - crew data should remain unchanged
        $this->assertEquals('Original Name', $response->displayName);
        $this->assertEquals('555-1234', $response->mobile);
        $this->assertEquals(SkillLevel::ADVANCED->value, $response->skill);
    }

    public function testResponseContainsCompleteCrewData(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $crewKey = $this->createCrewProfileForUser($userId);

        $request = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        // Act
        $response = $this->useCase->execute($userId, $request);

        // Assert - response should contain all crew fields
        $this->assertNotNull($response->key);
        $this->assertNotNull($response->firstName);
        $this->assertNotNull($response->lastName);
        $this->assertNotNull($response->skill);
        $this->assertIsArray($response->availabilities);
        $this->assertIsArray($response->rank);
        $this->assertIsArray($response->history);
        $this->assertIsArray($response->whitelist);
    }

    public function testConcurrentAvailabilityUpdatesForDifferentCrew(): void
    {
        // Arrange
        $userId1 = $this->createTestUser('crew1@example.com');
        $crewKey1 = $this->createCrewProfileForUser($userId1, ['key' => 'crew_1']);

        $userId2 = $this->createTestUser('crew2@example.com');
        $crewKey2 = $this->createCrewProfileForUser($userId2, ['key' => 'crew_2']);

        $request1 = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => true]
        ]);

        $request2 = new UpdateAvailabilityRequest([
            ['eventId' => 'Fri May 15', 'isAvailable' => false]
        ]);

        // Act
        $response1 = $this->useCase->execute($userId1, $request1);
        $response2 = $this->useCase->execute($userId2, $request2);

        // Assert - each crew should have independent availability
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $response1->availabilities['Fri May 15']);
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $response2->availabilities['Fri May 15']);

        // Verify in database
        $this->assertEquals(AvailabilityStatus::AVAILABLE->value, $this->getCrewAvailability('crew_1', 'Fri May 15'));
        $this->assertEquals(AvailabilityStatus::UNAVAILABLE->value, $this->getCrewAvailability('crew_2', 'Fri May 15'));
    }

}
