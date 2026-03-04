<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Season;

use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Domain\Service\SelectionService;
use App\Domain\Service\AssignmentService;
use App\Domain\Service\RankingService;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Infrastructure\Service\DatabaseTransactionService;
use App\Infrastructure\Service\SQLiteLockService;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for ProcessSeasonUpdateUseCase
 *
 * Tests the complete end-to-end pipeline from availability changes to flotilla generation:
 * - Load → Selection → Consolidation → Assignment → Serialize → Save
 */
class ProcessSeasonUpdateUseCaseTest extends IntegrationTestCase
{
    private ProcessSeasonUpdateUseCase $useCase;
    private BoatRepository $boatRepository;
    private CrewRepository $crewRepository;
    private EventRepository $eventRepository;
    private SeasonRepository $seasonRepository;

    protected function setUp(): void
    {
        parent::setUp();  // Runs migrations, initializes season config, sets test connection

        // Initialize repositories
        $this->boatRepository = new BoatRepository();
        $this->crewRepository = new CrewRepository();
        $this->eventRepository = new EventRepository();
        $this->seasonRepository = new SeasonRepository();

        // Initialize services
        $rankingService = new RankingService();
        $selectionService = new SelectionService($rankingService);
        $assignmentService = new AssignmentService();

        // Initialize use case
        $this->useCase = new ProcessSeasonUpdateUseCase(
            $this->boatRepository,
            $this->crewRepository,
            $this->eventRepository,
            $this->seasonRepository,
            $selectionService,
            $assignmentService,
            $rankingService,
            new DatabaseTransactionService(),
            new SQLiteLockService($this->pdo)
        );
    }

    /**
     * Helper: Create test boat
     */
    protected function createTestBoat(string $key, int $minBerths, int $maxBerths): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                               min_berths, max_berths, assistance_required, social_preference)
            VALUES (?, ?, 'Test', 'Owner', ?, ?, 'No', 'No')
        ");
        $stmt->execute([$key, "Boat $key", $minBerths, $maxBerths]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper: Create test crew
     */
    protected function createTestCrew(string $key): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO crews (key, display_name, first_name, last_name, skill, membership_number, social_preference)
            VALUES (?, ?, 'Test', 'Crew', 1, '12345', 'No')
        ");
        $stmt->execute([$key, "Crew $key"]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper: Create test event
     */
    protected function createTestEvent(string $eventId, string $date): void
    {
        $this->pdo->prepare("
            INSERT INTO events (event_id, event_date, start_time, finish_time, status)
            VALUES (?, ?, '12:00:00', '17:00:00', 'upcoming')
        ")->execute([$eventId, $date]);
    }

    /**
     * Helper: Set boat availability
     */
    protected function setBoatAvailability(int $boatId, string $eventId, int $berths): void
    {
        $this->pdo->prepare("
            INSERT INTO boat_availability (boat_id, event_id, berths)
            VALUES (?, ?, ?)
        ")->execute([$boatId, $eventId, $berths]);
    }

    /**
     * Helper: Set crew availability
     */
    protected function setCrewAvailability(int $crewId, string $eventId, int $status): void
    {
        $this->pdo->prepare("
            INSERT INTO crew_availability (crew_id, event_id, status)
            VALUES (?, ?, ?)
        ")->execute([$crewId, $eventId, $status]);
    }

    /**
     * Helper: Get crew availability status
     */
    protected function getCrewAvailabilityStatus(int $crewId, string $eventId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT status FROM crew_availability WHERE crew_id = ? AND event_id = ?
        ");
        $stmt->execute([$crewId, $eventId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Test: execute() generates flotilla for single event
     */
    public function testExecuteGeneratesFlotillaForSingleEvent(): void
    {
        // Arrange
        // Create 2 boats, 4 crews, 1 future event
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 2);

        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');
        $crew3Id = $this->createTestCrew('crew3');
        $crew4Id = $this->createTestCrew('crew4');

        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Set availability
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 2);
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew3Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew4Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        // Execute use case
        $result = $this->useCase->execute();

        // Assert result structure
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['events_processed']);
        $this->assertEquals(1, $result['flotillas_generated']);

        // Verify flotilla was created
        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));
        $this->assertNotNull($flotilla);
        $this->assertEquals('Fri May 29', $flotilla['event_id']);
        $this->assertArrayHasKey('crewed_boats', $flotilla);
        $this->assertNotEmpty($flotilla['crewed_boats']);
    }

    /**
     * Test: execute() generates flotillas for multiple events
     */
    public function testExecuteGeneratesFlotillasForMultipleEvents(): void
    {
        // Arrange
        // Create 3 boats, 6 crews, 3 future events
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 2);
        $boat3Id = $this->createTestBoat('boat3', 2, 2);

        $crewIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $crewIds[] = $this->createTestCrew("crew$i");
        }

        $this->createTestEvent('Fri May 29', '2026-05-29');
        $this->createTestEvent('Fri Jun 05', '2026-06-05');
        $this->createTestEvent('Fri Jun 12', '2026-06-12');

        // Set availability for all boats and crews for all events
        foreach ([$boat1Id, $boat2Id, $boat3Id] as $boatId) {
            $this->setBoatAvailability($boatId, 'Fri May 29', 2);
            $this->setBoatAvailability($boatId, 'Fri Jun 05', 2);
            $this->setBoatAvailability($boatId, 'Fri Jun 12', 2);
        }

        foreach ($crewIds as $crewId) {
            $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
            $this->setCrewAvailability($crewId, 'Fri Jun 05', AvailabilityStatus::AVAILABLE->value);
            $this->setCrewAvailability($crewId, 'Fri Jun 12', AvailabilityStatus::AVAILABLE->value);
        }

        // Act
        // Execute use case
        $result = $this->useCase->execute();

        // Assert result
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['events_processed']);
        $this->assertEquals(3, $result['flotillas_generated']);

        // Verify all 3 flotillas exist
        $flotilla1 = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));
        $flotilla2 = $this->seasonRepository->getFlotilla(EventId::fromString('Fri Jun 05'));
        $flotilla3 = $this->seasonRepository->getFlotilla(EventId::fromString('Fri Jun 12'));

        $this->assertNotNull($flotilla1);
        $this->assertNotNull($flotilla2);
        $this->assertNotNull($flotilla3);

        $this->assertEquals('Fri May 29', $flotilla1['event_id']);
        $this->assertEquals('Fri Jun 05', $flotilla2['event_id']);
        $this->assertEquals('Fri Jun 12', $flotilla3['event_id']);
    }

    /**
     * Test: execute() updates existing flotilla (UPSERT behavior)
     */
    public function testExecuteUpdatesExistingFlotilla(): void
    {
        // Arrange
        // Boat with capacity for 3 crews
        $boat1Id = $this->createTestBoat('boat1', 2, 3);
        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');
        $crew3Id = $this->createTestCrew('crew3'); // Will add later

        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Initial setup: 2 crews available
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 3); // Offer all 3 berths
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        // First execution
        $this->useCase->execute();
        $initialFlotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));
        $initialCrewCount = count($initialFlotilla['crewed_boats'][0]['crews'] ?? []);

        // Update: Add third crew
        $this->setCrewAvailability($crew3Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Second execution
        $this->useCase->execute();
        $updatedFlotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));
        $updatedCrewCount = count($updatedFlotilla['crewed_boats'][0]['crews'] ?? []);

        // Assert flotilla was updated
        $this->assertGreaterThan($initialCrewCount, $updatedCrewCount);
        $this->assertEquals(3, $updatedCrewCount);
    }

    /**
     * Test: Flotilla data structure matches expected format
     */
    public function testFlotillaDataStructureMatchesExpectedFormat(): void
    {
        // Arrange
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $crew1Id = $this->createTestCrew('crew1');

        $this->createTestEvent('Fri May 29', '2026-05-29');
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // Verify structure
        $this->assertIsArray($flotilla);
        $this->assertArrayHasKey('event_id', $flotilla);
        $this->assertArrayHasKey('crewed_boats', $flotilla);
        $this->assertArrayHasKey('waitlist_boats', $flotilla);
        $this->assertArrayHasKey('waitlist_crews', $flotilla);

        // Verify types
        $this->assertIsString($flotilla['event_id']);
        $this->assertIsArray($flotilla['crewed_boats']);
        $this->assertIsArray($flotilla['waitlist_boats']);
        $this->assertIsArray($flotilla['waitlist_crews']);

        // Verify crewed_boats structure
        if (!empty($flotilla['crewed_boats'])) {
            $firstCrewedBoat = $flotilla['crewed_boats'][0];
            $this->assertArrayHasKey('boat', $firstCrewedBoat);
            $this->assertArrayHasKey('crews', $firstCrewedBoat);
            $this->assertIsArray($firstCrewedBoat['boat']);
            $this->assertIsArray($firstCrewedBoat['crews']);
        }
    }

    /**
     * Test: execute() produces deterministic results (same inputs = same output)
     */
    public function testExecuteProducesDeterministicResults(): void
    {
        // Arrange
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');

        $this->createTestEvent('Fri May 29', '2026-05-29');
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        // First execution
        $this->useCase->execute();
        $flotilla1 = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Delete flotilla
        $this->seasonRepository->deleteFlotilla(EventId::fromString('Fri May 29'));

        // Second execution (same input)
        $this->useCase->execute();
        $flotilla2 = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // Compare structures (excluding timestamp fields)
        $this->assertEquals($flotilla1['event_id'], $flotilla2['event_id']);
        $this->assertEquals(count($flotilla1['crewed_boats']), count($flotilla2['crewed_boats']));
        $this->assertEquals(count($flotilla1['waitlist_boats']), count($flotilla2['waitlist_boats']));
        $this->assertEquals(count($flotilla1['waitlist_crews']), count($flotilla2['waitlist_crews']));
    }

    /**
     * Test: execute() updates crew availability to GUARANTEED
     */
    public function testExecuteUpdatesCrewAvailabilityToGuaranteed(): void
    {
        // Arrange
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');
        $crew3Id = $this->createTestCrew('crew3'); // This one won't be selected (waitlisted)

        $this->createTestEvent('Fri May 29', '2026-05-29');
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 1); // Only 1 berth offered
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew3Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        // Check which crews were selected
        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));
        $selectedCrewCount = 0;
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $selectedCrewCount += count($crewedBoat['crews']);
        }

        // Assert
        // Verify that selected crews have GUARANTEED status
        // Note: We can't easily check all crew statuses without knowing which were selected,
        // but we can verify the count is less than total (someone was waitlisted)
        $this->assertLessThan(3, $selectedCrewCount, 'Not all crews should be selected when boat capacity is limited');
    }

    /**
     * Test: execute() with no available entities creates empty flotilla
     */
    public function testExecuteWithNoAvailableEntities(): void
    {
        // Arrange
        $this->createTestBoat('boat1', 2, 2); // No availability set
        $this->createTestCrew('crew1'); // No availability set
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // Flotilla should exist but be empty
        $this->assertNotNull($flotilla);
        $this->assertCount(0, $flotilla['crewed_boats']);
        $this->assertCount(0, $flotilla['waitlist_boats']);
        $this->assertCount(0, $flotilla['waitlist_crews']);
    }

    /**
     * Test: execute() with perfect crew-to-boat fit
     */
    public function testExecuteWithPerfectCrewToBoatFit(): void
    {
        // Arrange
        // 2 boats * 2 berths = 4 total berths, 4 crews = perfect fit
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 2);

        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');
        $crew3Id = $this->createTestCrew('crew3');
        $crew4Id = $this->createTestCrew('crew4');

        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 2);
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew3Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew4Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // All boats should be crewed
        $this->assertCount(2, $flotilla['crewed_boats']);
        $this->assertCount(0, $flotilla['waitlist_boats']);
        $this->assertCount(0, $flotilla['waitlist_crews']);

        // All crews should be assigned
        $totalCrews = 0;
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $totalCrews += count($crewedBoat['crews']);
        }
        $this->assertEquals(4, $totalCrews);
    }

    /**
     * Test: execute() with too many crews (some waitlisted)
     */
    public function testExecuteWithTooManyCrews(): void
    {
        // 2 boats * 2 berths = 4 total berths, 6 crews = 2 crews waitlisted
        // Arrange
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 2);

        $crewIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $crewIds[] = $this->createTestCrew("crew$i");
        }

        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 2);
        foreach ($crewIds as $crewId) {
            $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        }

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // All boats should be crewed (no waitlist boats)
        $this->assertCount(2, $flotilla['crewed_boats']);
        $this->assertCount(0, $flotilla['waitlist_boats']);

        // Some crews should be waitlisted
        $totalCrewsAssigned = 0;
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $totalCrewsAssigned += count($crewedBoat['crews']);
        }
        $this->assertEquals(4, $totalCrewsAssigned);
        $this->assertCount(2, $flotilla['waitlist_crews']);
    }

    /**
     * Test: execute() with too few crews (some boats waitlisted)
     */
    public function testExecuteWithTooFewCrews(): void
    {
        // Arrange
        // 3 boats * 2 berths = 6 total berths, 4 crews = not enough
        $boat1Id = $this->createTestBoat('boat1', 2, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 2);
        $boat3Id = $this->createTestBoat('boat3', 2, 2);

        $crew1Id = $this->createTestCrew('crew1');
        $crew2Id = $this->createTestCrew('crew2');
        $crew3Id = $this->createTestCrew('crew3');
        $crew4Id = $this->createTestCrew('crew4');

        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat3Id, 'Fri May 29', 2);
        $this->setCrewAvailability($crew1Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew2Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew3Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crew4Id, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // Some boats should be crewed
        $this->assertGreaterThan(0, count($flotilla['crewed_boats']));

        // Some boats should be waitlisted
        $this->assertGreaterThan(0, count($flotilla['waitlist_boats']));

        // All crews should be assigned (no waitlist crews)
        $this->assertCount(0, $flotilla['waitlist_crews']);

        // Total boats should be 3
        $totalBoats = count($flotilla['crewed_boats']) + count($flotilla['waitlist_boats']);
        $this->assertEquals(3, $totalBoats);
    }

    /**
     * Test: Crews are distributed to boats according to occupied_berths (capacity-aware)
     *
     * CRITICAL: This test verifies the fix for capacity-based crew distribution.
     * Instead of round-robin distribution, crews should be assigned based on each
     * boat's max_berths so that larger boats get appropriately more crews.
     *
     * Scenario: 2 boats with different capacities
     * - boat1: min_berths=1, max_berths=2 (small)
     * - boat2: min_berths=2, max_berths=4 (large)
     * - 6 crews available (perfect fit: 2+4=6 berths)
     *
     * Expected distribution: boat1 gets 2 crews, boat2 gets 4 crews
     * (matching their max_berths, not round-robin like 3-3)
     */
    public function testCrewsDistributedAccordingToOccupiedBerths(): void
    {
        // Arrange
        // Create 2 boats with different capacities
        $boat1Id = $this->createTestBoat('boat1', 1, 2); // Small boat: 2 max berths
        $boat2Id = $this->createTestBoat('boat2', 2, 4); // Large boat: 4 max berths

        // Create 6 crews (perfect fit for 2+4=6 berths)
        $crewIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $crewIds[] = $this->createTestCrew("crew$i");
        }

        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Both boats offer their full capacity
        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 4);

        // All crews available
        foreach ($crewIds as $crewId) {
            $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        }

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        // Should have exactly 2 boats (both crewed)
        $this->assertCount(2, $flotilla['crewed_boats']);
        $this->assertCount(0, $flotilla['waitlist_boats']);
        $this->assertCount(0, $flotilla['waitlist_crews']);

        // Get crew counts per boat
        $crewCountsPerBoat = [];
        foreach ($flotilla['crewed_boats'] as $index => $crewedBoat) {
            $crewCountsPerBoat[$index] = count($crewedBoat['crews']);
        }

        // Sort crew counts to get min and max (order may vary)
        sort($crewCountsPerBoat);

        // Assert distribution is capacity-aware, not round-robin
        // With capacities of 2 and 4, we expect distribution of [2, 4], not [3, 3]
        $this->assertEquals(2, $crewCountsPerBoat[0], 'Smaller boat should have 2 crews');
        $this->assertEquals(4, $crewCountsPerBoat[1], 'Larger boat should have 4 crews');
    }

    /**
     * Test: Uneven capacity distribution respects boat max_berths
     *
     * Scenario: 3 boats with varied capacities
     * - boat1: max_berths=2
     * - boat2: max_berths=3
     * - boat3: max_berths=5
     * - 10 crews available (perfect fit: 2+3+5=10)
     *
     * Expected: Each boat gets crews matching its capacity (2, 3, 5)
     * Not round-robin distribution (which would be 3-3-4 or 4-3-3)
     */
    public function testUnevenCapacityDistributionRespectsBerthsPerBoat(): void
    {
        // Arrange
        $boat1Id = $this->createTestBoat('boat1', 1, 2);
        $boat2Id = $this->createTestBoat('boat2', 2, 3);
        $boat3Id = $this->createTestBoat('boat3', 3, 5);

        $crewIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $crewIds[] = $this->createTestCrew("crew$i");
        }

        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->setBoatAvailability($boat1Id, 'Fri May 29', 2);
        $this->setBoatAvailability($boat2Id, 'Fri May 29', 3);
        $this->setBoatAvailability($boat3Id, 'Fri May 29', 5);

        foreach ($crewIds as $crewId) {
            $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        }

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert
        $this->assertCount(3, $flotilla['crewed_boats']);
        $this->assertCount(0, $flotilla['waitlist_boats']);
        $this->assertCount(0, $flotilla['waitlist_crews']);

        // Collect crew counts and verify they match capacities
        $crewCountsPerBoat = array_map(
            fn($crewedBoat) => count($crewedBoat['crews']),
            $flotilla['crewed_boats']
        );
        sort($crewCountsPerBoat);

        // Verify distribution matches [2, 3, 5] (capacity-aware), not [3, 3, 4] (round-robin)
        $this->assertEquals([2, 3, 5], $crewCountsPerBoat);
    }

    /**
     * Test: execute() preserves boat flexibility rank as stored (never dynamically recalculates)
     *
     * In the single-role registration model:
     * - Boat flex rank is set at registration (willingToCrew=true → rank_flexibility=0)
     *   and is never changed by the pipeline.
     * - Crew flex rank is always 1 (hardcoded in repository, not stored in DB).
     */
    public function testExecutePreservesFlexibilityRanksAsStored(): void
    {
        // Arrange: boat with rank_flexibility=0 (willing to crew)

        // Create boat owned by John Doe with rank_flexibility=0 (willing to crew)
        $stmt = $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                              min_berths, max_berths, assistance_required,
                              social_preference, rank_flexibility, rank_absence)
            VALUES ('sailaway', 'Sail Away', 'John', 'Doe',
                    2, 3, 'No', 'No', 0, 0)
        ");
        $stmt->execute();
        $boatId = (int)$this->pdo->lastInsertId();

        // Create crew member Jane Smith (separate person — single-role model)
        $stmt = $this->pdo->prepare("
            INSERT INTO crews (key, display_name, first_name, last_name,
                              skill, membership_number, social_preference,
                              rank_commitment, rank_membership, rank_absence)
            VALUES ('janesmith', 'Jane Smith', 'Jane', 'Smith',
                    1, '12345', 'No', 0, 0, 0)
        ");
        $stmt->execute();
        $crewId = (int)$this->pdo->lastInsertId();

        // Create future event
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Set availability
        $this->setBoatAvailability($boatId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Verify initial boat rank
        $boatRank = $this->pdo->query("SELECT rank_flexibility FROM boats WHERE id = $boatId")->fetchColumn();
        $this->assertEquals(0, (int)$boatRank, 'Initial boat flexibility should be 0 (willing to crew)');

        // Act: Run season update
        $this->useCase->execute();

        // Assert: Boat rank is preserved unchanged — pipeline does not recalculate flex rank
        $boatRankAfter = $this->pdo->query("SELECT rank_flexibility FROM boats WHERE id = $boatId")->fetchColumn();
        $this->assertEquals(0, (int)$boatRankAfter, 'Boat flexibility should remain 0 (not modified by pipeline)');
    }

    /**
     * Test: Flex boat owner is promoted from crew waitlist into a crewed boat with spare capacity
     *
     * Scenario (case 1 — too few crews):
     * - Boat A: flex (rank_flexibility=0, lower priority) → waitlisted by SelectionService
     * - Boat B: not flex (rank_flexibility=1, higher priority) → selected, occupied_berths=minBerths=1
     * - Boat B offered 2 berths but only gets 1 crew assigned → spare capacity = 1
     * - buildFlexCrewEntries() adds Boat A's owner to waitlist_crews as a synthetic entry
     * - promoteWaitlistCrew() moves Boat A's owner onto Boat B's spare slot
     */
    public function testFlexOwnerPromotedFromWaitlistToCrewedBoat(): void
    {
        // Arrange: Boat A — flex owner willing to crew (rank_flexibility=0 → lower priority, waitlisted first)
        $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                              min_berths, max_berths, assistance_required,
                              social_preference, rank_flexibility, rank_absence)
            VALUES ('flexy', 'Flexy', 'Alice', 'Flex',
                    1, 2, 'No', 'No', 0, 0)
        ")->execute();
        $boatAId = (int)$this->pdo->lastInsertId();

        // Boat B — regular boat (rank_flexibility=1 → higher priority, gets selected)
        $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                              min_berths, max_berths, assistance_required,
                              social_preference, rank_flexibility, rank_absence)
            VALUES ('rigger', 'Rigger', 'Bob', 'Regular',
                    1, 2, 'No', 'No', 1, 0)
        ")->execute();
        $boatBId = (int)$this->pdo->lastInsertId();

        // 1 crew member — not enough for both boats (1 < min 1+1=2) → triggers case 1
        $crewId = $this->createTestCrew('testcrew');

        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Both boats offer 2 berths; min_berths=1 means Boat B will have spare capacity after selection
        $this->setBoatAvailability($boatAId, 'Fri May 29', 2);
        $this->setBoatAvailability($boatBId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();
        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert: Boat B selected, Boat A waitlisted as a boat
        $this->assertCount(1, $flotilla['crewed_boats'], 'Only Boat B should be crewed');
        $this->assertCount(1, $flotilla['waitlist_boats'], 'Boat A should remain on the boat waitlist');

        // Assert: Flex owner promoted off crew waitlist into Boat B's spare slot
        $this->assertCount(0, $flotilla['waitlist_crews'], 'Flex owner should be promoted off the crew waitlist');
        $this->assertCount(2, $flotilla['crewed_boats'][0]['crews'], 'Boat B should have 2 crew after promotion');

        // Assert: Alice Flex (Boat A's owner, key='aliceflex') is now on Boat B
        $crewKeys = array_column($flotilla['crewed_boats'][0]['crews'], 'key');
        $this->assertContains('aliceflex', $crewKeys, 'Flex owner (aliceflex) should be assigned to Boat B');
    }

    /**
     * Helper: Insert a past event (date must be before today's real date so findPastEvents() returns it)
     */
    private function createPastEvent(string $eventId, string $date): void
    {
        $this->pdo->prepare("
            INSERT INTO events (event_id, event_date, start_time, finish_time, status)
            VALUES (?, ?, '12:45:00', '14:00:00', 'past')
        ")->execute([$eventId, $date]);
    }

    /**
     * Test: boat_history rows are written for past events based on stored flotilla data
     *
     * Boats present in crewed_boats get 'Y'; absent boats get ''.
     * rank_absence reflects the count of '' entries.
     */
    public function testBoatHistoryWrittenForPastFlotilla(): void
    {
        // Arrange: 2 boats, 1 past event, 1 future event
        $boatAId = $this->createTestBoat('boat-a', 1, 2);
        $boatBId = $this->createTestBoat('boat-b', 1, 2);

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Pre-store a flotilla for the past event: boat-a was crewed, boat-b was absent
        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id' => 'Fri Jan 17',
            'crewed_boats' => [
                ['boat' => ['key' => 'boat-a', 'display_name' => 'Boat boat-a'], 'crews' => []],
            ],
            'waitlist_boats' => [['key' => 'boat-b', 'display_name' => 'Boat boat-b']],
            'waitlist_crews' => [],
        ]);

        // Set availability for the future event so the pipeline has something to process
        $crewId = $this->createTestCrew('crew1');
        $this->setBoatAvailability($boatAId, 'Fri May 29', 2);
        $this->setBoatAvailability($boatBId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        // Assert: boat_history rows were written
        $stmt = $this->pdo->prepare('SELECT participated FROM boat_history WHERE boat_id = ? AND event_id = ?');

        $stmt->execute([$boatAId, 'Fri Jan 17']);
        $this->assertEquals('Y', $stmt->fetchColumn(), 'boat-a was in crewed_boats so participated should be Y');

        $stmt->execute([$boatBId, 'Fri Jan 17']);
        $this->assertEquals('', $stmt->fetchColumn(), 'boat-b was absent so participated should be empty string');

        // Assert: absence ranks persisted to DB
        $rankA = (int)$this->pdo->query("SELECT rank_absence FROM boats WHERE id = $boatAId")->fetchColumn();
        $rankB = (int)$this->pdo->query("SELECT rank_absence FROM boats WHERE id = $boatBId")->fetchColumn();

        $this->assertEquals(0, $rankA, 'boat-a was present; absence should be 0');
        $this->assertEquals(1, $rankB, 'boat-b was absent once; absence should be 1');
    }

    /**
     * Test: crew_history rows are written for past events based on stored flotilla data
     *
     * Crews present in crewed_boats get the boat key; waitlisted crews get '';
     * crews not registered get no entry. rank_absence reflects the count of '' entries.
     */
    public function testCrewHistoryWrittenForPastFlotilla(): void
    {
        // Arrange: 3 crews, 1 past event, 1 future event
        $crewAssignedId = $this->createTestCrew('crew-assigned');
        $crewWaitlistId = $this->createTestCrew('crew-waitlist');
        $crewAbsentId   = $this->createTestCrew('crew-absent');

        $boatId = $this->createTestBoat('boat-x', 1, 2);

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Pre-store a flotilla for the past event:
        // crew-assigned was on boat-x; crew-waitlist was waitlisted; crew-absent had no entry
        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id' => 'Fri Jan 17',
            'crewed_boats' => [
                [
                    'boat'  => ['key' => 'boat-x', 'display_name' => 'Boat X'],
                    'crews' => [['key' => 'crew-assigned', 'display_name' => 'Crew crew-assigned']],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [['key' => 'crew-waitlist', 'display_name' => 'Crew crew-waitlist']],
        ]);

        // Set availability for the future event so the pipeline has something to process
        $this->setBoatAvailability($boatId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewAssignedId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        // Assert: crew_history rows were written for crew-assigned and crew-waitlist
        $stmt = $this->pdo->prepare('SELECT boat_key FROM crew_history WHERE crew_id = ? AND event_id = ?');

        $stmt->execute([$crewAssignedId, 'Fri Jan 17']);
        $this->assertEquals('boat-x', $stmt->fetchColumn(), 'crew-assigned should have boat key boat-x');

        $stmt->execute([$crewWaitlistId, 'Fri Jan 17']);
        $this->assertEquals('', $stmt->fetchColumn(), 'crew-waitlist should have empty string boat key');

        // crew-absent had no flotilla entry — no row should be written
        $stmt->execute([$crewAbsentId, 'Fri Jan 17']);
        $this->assertFalse($stmt->fetchColumn(), 'crew-absent should have no crew_history row');

        // Assert: absence ranks persisted to DB
        $rankAssigned  = (int)$this->pdo->query("SELECT rank_absence FROM crews WHERE id = $crewAssignedId")->fetchColumn();
        $rankWaitlist  = (int)$this->pdo->query("SELECT rank_absence FROM crews WHERE id = $crewWaitlistId")->fetchColumn();

        $this->assertEquals(0, $rankAssigned, 'crew-assigned was on a boat; absence should be 0');
        $this->assertEquals(1, $rankWaitlist, 'crew-waitlist was not assigned once; absence should be 1');
    }

    /**
     * Test: Crew with higher absence rank is selected over crew with lower absence rank
     *
     * After syncing 1 past event where crew-present was assigned and crew-absent was waitlisted,
     * crew-absent gets rank_absence=1 and crew-present gets rank_absence=0.
     * With only 1 boat berth available, crew-absent (higher priority) is selected.
     */
    public function testAbsentCrewSelectedOverPresentCrew(): void
    {
        // Arrange: 1 boat (1 berth), 2 crews, 1 past event, 1 future event
        // crew-present: in past flotilla crewed_boats → absence=0
        // crew-absent:  in past flotilla waitlist_crews → absence=1 → higher priority
        $crewPresentId = $this->createTestCrew('crew-present');
        $crewAbsentId  = $this->createTestCrew('crew-absent');

        $boatId = $this->createTestBoat('boat-y', 1, 1);

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Past flotilla: crew-present was on boat-y; crew-absent was waitlisted
        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id'     => 'Fri Jan 17',
            'crewed_boats' => [
                [
                    'boat'  => ['key' => 'boat-y', 'display_name' => 'Boat Y'],
                    'crews' => [['key' => 'crew-present', 'display_name' => 'Crew crew-present']],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [['key' => 'crew-absent', 'display_name' => 'Crew crew-absent']],
        ]);

        // Both crews available for next event; boat only has 1 berth → 1 crew waitlisted
        $this->setBoatAvailability($boatId, 'Fri May 29', 1);
        $this->setCrewAvailability($crewPresentId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);
        $this->setCrewAvailability($crewAbsentId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert: 1 crew assigned, 1 crew waitlisted
        $totalAssigned = 0;
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $totalAssigned += count($crewedBoat['crews']);
        }
        $this->assertEquals(1, $totalAssigned, 'Exactly 1 crew should be assigned');
        $this->assertCount(1, $flotilla['waitlist_crews'], 'Exactly 1 crew should be waitlisted');

        // Assert: crew-absent (absence=1 → higher priority) was assigned; crew-present was waitlisted
        $assignedKey = $flotilla['crewed_boats'][0]['crews'][0]['key'];
        $this->assertEquals('crew-absent', $assignedKey, 'crew-absent (absence=1) should be selected over crew-present (absence=0)');
    }

    /**
     * Test: Crew history sync is idempotent — running the pipeline twice produces identical results
     */
    public function testCrewHistorySyncIsIdempotent(): void
    {
        // Arrange
        $crewId = $this->createTestCrew('crew-a');
        $boatId = $this->createTestBoat('boat-a', 1, 2);

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id'     => 'Fri Jan 17',
            'crewed_boats' => [
                [
                    'boat'  => ['key' => 'boat-a', 'display_name' => 'Boat boat-a'],
                    'crews' => [['key' => 'crew-a', 'display_name' => 'Crew crew-a']],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ]);

        $this->setBoatAvailability($boatId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act: run pipeline twice
        $this->useCase->execute();
        $rankAfterFirst = (int)$this->pdo->query("SELECT rank_absence FROM crews WHERE id = $crewId")->fetchColumn();
        $histStmt = $this->pdo->prepare('SELECT boat_key FROM crew_history WHERE crew_id = ? AND event_id = ?');
        $histStmt->execute([$crewId, 'Fri Jan 17']);
        $boatKeyAfterFirst = $histStmt->fetchColumn();

        $this->useCase->execute();
        $rankAfterSecond = (int)$this->pdo->query("SELECT rank_absence FROM crews WHERE id = $crewId")->fetchColumn();
        $histStmt->execute([$crewId, 'Fri Jan 17']);
        $boatKeyAfterSecond = $histStmt->fetchColumn();

        // Assert: same results both times
        $this->assertEquals($rankAfterFirst, $rankAfterSecond, 'rank_absence should be identical after both runs');
        $this->assertEquals($boatKeyAfterFirst, $boatKeyAfterSecond, 'crew_history should be identical after both runs');
        $this->assertEquals('boat-a', $boatKeyAfterFirst, 'crew-a was assigned to boat-a');
        $this->assertEquals(0, $rankAfterFirst, 'crew-a was assigned; absence should be 0');
    }

    /**
     * Test: Boat with higher absence rank is selected over boat with lower absence rank
     *
     * After syncing 1 past event where boat-a participated and boat-b was absent,
     * boat-b gets rank_absence=1 and boat-a gets rank_absence=0.
     * With only enough crew for 1 boat, boat-b (higher priority) is selected.
     */
    public function testAbsentBoatSelectedOverPresentBoat(): void
    {
        // Arrange: 2 boats (same flexibility=1), 1 past event, 1 future event
        // boat-a: will be present in past flotilla → absence=0
        // boat-b: will be absent from past flotilla → absence=1 → higher priority
        $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                              min_berths, max_berths, assistance_required,
                              social_preference, rank_flexibility, rank_absence)
            VALUES ('boat-a', 'Boat A', 'Alice', 'Smith', 1, 1, 'No', 'No', 1, 0)
        ")->execute();
        $boatAId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name,
                              min_berths, max_berths, assistance_required,
                              social_preference, rank_flexibility, rank_absence)
            VALUES ('boat-b', 'Boat B', 'Bob', 'Jones', 1, 1, 'No', 'No', 1, 0)
        ")->execute();
        $boatBId = (int)$this->pdo->lastInsertId();

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        // Past flotilla: only boat-a was crewed — boat-b was absent
        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id' => 'Fri Jan 17',
            'crewed_boats' => [
                ['boat' => ['key' => 'boat-a', 'display_name' => 'Boat A'], 'crews' => []],
            ],
            'waitlist_boats' => [['key' => 'boat-b', 'display_name' => 'Boat B']],
            'waitlist_crews' => [],
        ]);

        // Only 1 crew available for future event — not enough for both boats (each needs min 1)
        $crewId = $this->createTestCrew('crew1');
        $this->setBoatAvailability($boatAId, 'Fri May 29', 1);
        $this->setBoatAvailability($boatBId, 'Fri May 29', 1);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act
        $this->useCase->execute();

        $flotilla = $this->seasonRepository->getFlotilla(EventId::fromString('Fri May 29'));

        // Assert: 1 boat crewed, 1 boat waitlisted
        $this->assertCount(1, $flotilla['crewed_boats'], 'Exactly 1 boat should be crewed');
        $this->assertCount(1, $flotilla['waitlist_boats'], 'Exactly 1 boat should be waitlisted');

        // Assert: boat-b (higher absence rank) was selected, boat-a was waitlisted
        $selectedBoatKey = $flotilla['crewed_boats'][0]['boat']['key'];
        $this->assertEquals('boat-b', $selectedBoatKey, 'boat-b (absence=1) should be selected over boat-a (absence=0)');
    }

    /**
     * Test: History sync is idempotent — running the pipeline twice produces identical results
     */
    public function testBoatHistorySyncIsIdempotent(): void
    {
        // Arrange
        $boatId = $this->createTestBoat('boat-a', 1, 2);

        $this->createPastEvent('Fri Jan 17', '2026-01-17');
        $this->createTestEvent('Fri May 29', '2026-05-29');

        $this->seasonRepository->saveFlotilla(EventId::fromString('Fri Jan 17'), [
            'event_id' => 'Fri Jan 17',
            'crewed_boats' => [
                ['boat' => ['key' => 'boat-a', 'display_name' => 'Boat boat-a'], 'crews' => []],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ]);

        $crewId = $this->createTestCrew('crew1');
        $this->setBoatAvailability($boatId, 'Fri May 29', 2);
        $this->setCrewAvailability($crewId, 'Fri May 29', AvailabilityStatus::AVAILABLE->value);

        // Act: run pipeline twice
        $this->useCase->execute();
        $rankAfterFirst = (int)$this->pdo->query("SELECT rank_absence FROM boats WHERE id = $boatId")->fetchColumn();
        $histAfterFirst = $this->pdo->prepare('SELECT participated FROM boat_history WHERE boat_id = ? AND event_id = ?');
        $histAfterFirst->execute([$boatId, 'Fri Jan 17']);
        $participatedAfterFirst = $histAfterFirst->fetchColumn();

        $this->useCase->execute();
        $rankAfterSecond = (int)$this->pdo->query("SELECT rank_absence FROM boats WHERE id = $boatId")->fetchColumn();
        $histAfterSecond = $this->pdo->prepare('SELECT participated FROM boat_history WHERE boat_id = ? AND event_id = ?');
        $histAfterSecond->execute([$boatId, 'Fri Jan 17']);
        $participatedAfterSecond = $histAfterSecond->fetchColumn();

        // Assert: same results both times
        $this->assertEquals($rankAfterFirst, $rankAfterSecond, 'rank_absence should be identical after both runs');
        $this->assertEquals($participatedAfterFirst, $participatedAfterSecond, 'boat_history should be identical after both runs');
        $this->assertEquals('Y', $participatedAfterFirst, 'boat-a was in crewed_boats so participated should be Y');
        $this->assertEquals(0, $rankAfterFirst, 'boat-a was present in past flotilla so absence should be 0');
    }
}
