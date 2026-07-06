<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Crew;

use App\Application\UseCase\Crew\GetCrewAvailabilityUseCase;
use App\Application\UseCase\Crew\GetUserAssignmentsUseCase;
use App\Application\Exception\CrewNotFoundException;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Infrastructure\Service\SystemTimeService;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Crew Query UseCase operations
 *
 * Tests crew-related query functionality including:
 * - Getting crew availability across events
 * - Getting user assignments and boat details
 */
class CrewQueryUseCaseTest extends IntegrationTestCase
{
    private GetCrewAvailabilityUseCase $getCrewAvailabilityUseCase;
    private GetUserAssignmentsUseCase $getUserAssignmentsUseCase;
    private BoatRepository $boatRepository;
    private CrewRepository $crewRepository;
    private EventRepository $eventRepository;
    private SeasonRepository $seasonRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boatRepository = new BoatRepository();
        $this->crewRepository = new CrewRepository();
        $this->seasonRepository = new SeasonRepository();
        $this->eventRepository = new EventRepository(new SystemTimeService($this->seasonRepository));
        $this->userRepository = new UserRepository();

        $this->getCrewAvailabilityUseCase = new GetCrewAvailabilityUseCase(
            $this->crewRepository,
            $this->eventRepository
        );

        $this->getUserAssignmentsUseCase = new GetUserAssignmentsUseCase(
            $this->crewRepository,
            $this->boatRepository,
            $this->eventRepository,
            $this->seasonRepository
        );

        $this->initializeTestData();
    }

    protected function initializeTestData(): void
    {
        // Create test events
        $this->createTestEvent('Fri May 15', '2026-05-15');
        $this->createTestEvent('Fri May 22', '2026-05-22');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Create test user and crew
        $user = new User('testcrew@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $crew = new Crew(
            key: CrewKey::fromName('John', 'Sailor'),
            displayName: 'John Sailor',
            firstName: 'John',
            lastName: 'Sailor',
            partnerKey: null,
            mobile: '1234567890',
            socialPreference: false,
            membershipNumber: 'M123',
            skill: SkillLevel::INTERMEDIATE,
            experience: null
        );
        $crew->setUserId($user->getId());

        // Set availability for events
        $crew->setAvailability(EventId::fromString('Fri May 15'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('Fri May 22'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('Fri May 29'), AvailabilityStatus::SELECTED);

        $this->crewRepository->save($crew);
    }

    // ==================== GetCrewAvailabilityUseCase Tests ====================

    public function testGetCrewAvailabilityThrowsExceptionWhenCrewNotFound(): void
    {
        $this->expectException(CrewNotFoundException::class);

        $this->getCrewAvailabilityUseCase->execute(999999);
    }

    public function testGetCrewAvailabilityReturnsAvailabilityResponse(): void
    {
        // Get user ID
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $response = $this->getCrewAvailabilityUseCase->execute($userId);

        $this->assertInstanceOf(\App\Application\DTO\Response\AvailabilityResponse::class, $response);
    }

    public function testGetCrewAvailabilityReturnsCorrectBooleanValues(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $response = $this->getCrewAvailabilityUseCase->execute($userId);
        $availability = $response->availability;

        // AVAILABLE and GUARANTEED should be true, UNAVAILABLE should be false
        $this->assertTrue($availability['2026-05-15']); // AVAILABLE
        $this->assertFalse($availability['2026-05-22']); // UNAVAILABLE
        $this->assertTrue($availability['2026-05-29']); // GUARANTEED
    }

    public function testGetCrewAvailabilityUsesIsoDateFormat(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $response = $this->getCrewAvailabilityUseCase->execute($userId);
        $availability = $response->availability;

        $this->assertArrayHasKey('2026-05-15', $availability);
        $this->assertArrayHasKey('2026-05-22', $availability);
        $this->assertArrayHasKey('2026-05-29', $availability);
    }

    public function testGetCrewAvailabilityIncludesAllEvents(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $response = $this->getCrewAvailabilityUseCase->execute($userId);
        $availability = $response->availability;

        $this->assertCount(3, $availability);
    }

    public function testGetCrewAvailabilityHandlesNoAvailabilitySet(): void
    {
        // Create new user and crew without availability
        $newUser = new User('newcrew@example.com', 'hash', 'crew', false);
        $this->userRepository->save($newUser);

        $newCrew = new Crew(
            key: CrewKey::fromName('Jane', 'Doe'),
            displayName: 'Jane Doe',
            firstName: 'Jane',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: 'M456',
            skill: SkillLevel::NOVICE,
            experience: null
        );
        $newCrew->setUserId($newUser->getId());
        $this->crewRepository->save($newCrew);

        $response = $this->getCrewAvailabilityUseCase->execute($newUser->getId());
        $availability = $response->availability;

        // Should return empty or default values
        $this->assertIsArray($availability);
    }

    // ==================== GetUserAssignmentsUseCase Tests ====================

    public function testGetUserAssignmentsReturnsEmptyArrayWhenUserNotFound(): void
    {
        // Users who are neither crew nor boat owners should get an empty array
        $assignments = $this->getUserAssignmentsUseCase->execute(999999);

        $this->assertIsArray($assignments);
        $this->assertEmpty($assignments);
    }

    public function testGetUserAssignmentsReturnsArrayOfAssignments(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);

        $this->assertIsArray($assignments);
        $this->assertCount(3, $assignments); // One for each event
    }

    public function testGetUserAssignmentsIncludesEventDetails(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);
        $firstAssignment = $assignments[0];

        $this->assertInstanceOf(\App\Application\DTO\Response\AssignmentResponse::class, $firstAssignment);
        $this->assertNotNull($firstAssignment->eventId);
        $this->assertNotNull($firstAssignment->eventDate);
        $this->assertNotNull($firstAssignment->startTime);
        $this->assertNotNull($firstAssignment->finishTime);
    }

    public function testGetUserAssignmentsShowsNoBoatWhenFlotillaNotGenerated(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);

        foreach ($assignments as $assignment) {
            $this->assertNull($assignment->boatName);
            $this->assertNull($assignment->boatKey);
            $this->assertEmpty($assignment->crewmates);
        }
    }

    public function testGetUserAssignmentsIncludesBoatWhenAssigned(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        // Create flotilla with assignment
        $flotilla = [
            'event_id' => 'Fri May 15',
            'crewed_boats' => [
                [
                    'boat' => [
                        'key' => 'Test Boat',
                        'display_name' => 'Test Boat',
                    ],
                    'crews' => [
                        [
                            'key' => 'johnsailor',
                            'display_name' => 'John Sailor',
                            'skill' => 1,
                        ],
                        [
                            'key' => 'janedoe',
                            'display_name' => 'Jane Doe',
                            'skill' => 2,
                        ],
                    ],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES ('Fri May 15', '" . json_encode($flotilla) . "', CURRENT_TIMESTAMP)
        ");

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);
        $may15Assignment = array_values(array_filter(
            $assignments,
            fn($a) => $a->eventId === 'Fri May 15'
        ))[0];

        $this->assertEquals('Test Boat', $may15Assignment->boatName);
        $this->assertCount(1, $may15Assignment->crewmates); // Jane Doe
    }

    public function testGetUserAssignmentsIncludesCrewmates(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        // Create flotilla with multiple crew
        $flotilla = [
            'event_id' => 'Fri May 22',
            'crewed_boats' => [
                [
                    'boat' => [
                        'key' => 'Another Boat',
                        'display_name' => 'Another Boat',
                    ],
                    'crews' => [
                        [
                            'key' => 'johnsailor',
                            'display_name' => 'John Sailor',
                            'skill' => 1,
                        ],
                        [
                            'key' => 'alicesmith',
                            'display_name' => 'Alice Smith',
                            'skill' => 2,
                        ],
                        [
                            'key' => 'bobjones',
                            'display_name' => 'Bob Jones',
                            'skill' => 0,
                        ],
                    ],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES ('Fri May 22', '" . json_encode($flotilla) . "', CURRENT_TIMESTAMP)
        ");

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);
        $may22Assignment = array_values(array_filter(
            $assignments,
            fn($a) => $a->eventId === 'Fri May 22'
        ))[0];

        $this->assertCount(2, $may22Assignment->crewmates); // Alice and Bob, not John
        $this->assertEquals('Alice Smith', $may22Assignment->crewmates[0]['display_name']);
    }

    public function testGetUserAssignmentsIncludesAvailabilityStatus(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);

        foreach ($assignments as $assignment) {
            $this->assertIsInt($assignment->availabilityStatus);
            $this->assertGreaterThanOrEqual(0, $assignment->availabilityStatus);
            $this->assertLessThanOrEqual(3, $assignment->availabilityStatus);
        }
    }

    public function testGetUserAssignmentsOrderedByEventDate(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);

        $dates = array_map(fn($a) => $a->eventDate, $assignments);
        $this->assertEquals($dates, ['2026-05-15', '2026-05-22', '2026-05-29']);
    }

    public function testGetUserAssignmentsForMultipleEventsWithDifferentBoats(): void
    {
        $user = $this->userRepository->findByEmail('testcrew@example.com');
        $userId = $user->getId();

        // Create flotillas for multiple events with different boats
        $flotilla1 = [
            'event_id' => 'Fri May 15',
            'crewed_boats' => [
                [
                    'boat' => ['key' => 'Boat A', 'display_name' => 'Boat A'],
                    'crews' => [['key' => 'johnsailor', 'display_name' => 'John Sailor', 'skill' => 1]],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        $flotilla2 = [
            'event_id' => 'Fri May 29',
            'crewed_boats' => [
                [
                    'boat' => ['key' => 'Boat B', 'display_name' => 'Boat B'],
                    'crews' => [['key' => 'johnsailor', 'display_name' => 'John Sailor', 'skill' => 1]],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES
                ('Fri May 15', '" . json_encode($flotilla1) . "', CURRENT_TIMESTAMP),
                ('Fri May 29', '" . json_encode($flotilla2) . "', CURRENT_TIMESTAMP)
        ");

        $assignments = $this->getUserAssignmentsUseCase->execute($userId);

        $may15 = array_values(array_filter($assignments, fn($a) => $a->eventId === 'Fri May 15'))[0];
        $may29 = array_values(array_filter($assignments, fn($a) => $a->eventId === 'Fri May 29'))[0];

        $this->assertEquals('Boat A', $may15->boatName);
        $this->assertEquals('Boat B', $may29->boatName);
    }
}
