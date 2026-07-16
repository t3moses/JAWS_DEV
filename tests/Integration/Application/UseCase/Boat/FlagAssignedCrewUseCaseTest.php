<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Boat;

use App\Application\Exception\BoatNotFoundException;
use App\Application\UseCase\Boat\FlagAssignedCrewUseCase;
use App\Domain\ValueObject\EventId;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Service\SystemTimeService;
use Psr\Log\NullLogger;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for FlagAssignedCrewUseCase
 *
 * Verifies that a boat owner can decrement the commitment rank of crew
 * genuinely assigned to their boat, that client-submitted (eventId, crewKey)
 * pairs are independently verified against the persisted flotilla (never
 * trusted at face value), and that the decrement is clamped to 0-2.
 */
class FlagAssignedCrewUseCaseTest extends IntegrationTestCase
{
    private FlagAssignedCrewUseCase $useCase;
    private BoatRepository $boatRepository;
    private CrewRepository $crewRepository;
    private SeasonRepository $seasonRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestEvent('Fri May 15', '2026-05-15');
        $this->createTestEvent('Fri May 22', '2026-05-22');

        $this->boatRepository = new BoatRepository();
        $this->crewRepository = new CrewRepository();
        $this->seasonRepository = new SeasonRepository();
        $eventRepository = new EventRepository(new SystemTimeService($this->seasonRepository));

        $this->useCase = new FlagAssignedCrewUseCase(
            $this->boatRepository,
            $this->crewRepository,
            $eventRepository,
            $this->seasonRepository,
            new NullLogger()
        );
    }

    // ==================== HELPER METHODS ====================

    protected function createBoatProfileForUser(int $userId, array $overrides = []): string
    {
        $key = $overrides['key'] ?? 'boat_' . $userId;

        $stmt = $this->pdo->prepare('
            INSERT INTO boats (
                key, display_name, owner_first_name, owner_last_name,
                min_berths, max_berths, assistance_required, social_preference,
                owner_user_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $key,
            $overrides['displayName'] ?? 'Test Boat',
            'Test',
            'Owner',
            $overrides['minBerths'] ?? 1,
            $overrides['maxBerths'] ?? 4,
            'No',
            'No',
            $userId,
        ]);

        return $key;
    }

    protected function createCrewProfileForUser(int $userId, array $overrides = []): string
    {
        $key = $overrides['key'] ?? 'crew_' . $userId;

        $stmt = $this->pdo->prepare('
            INSERT INTO crews (
                key, display_name, first_name, last_name, skill,
                commitment_rank, user_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $key,
            $overrides['displayName'] ?? 'Test Crew',
            'Test',
            'Crew',
            1,
            $overrides['commitmentRank'] ?? 2,
            $userId,
        ]);

        return $key;
    }

    protected function getCommitmentRank(string $crewKey): int
    {
        $stmt = $this->pdo->prepare('SELECT commitment_rank FROM crews WHERE key = ?');
        $stmt->execute([$crewKey]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Persist a minimal flotilla where $crewKeys are assigned to $boatKey for $eventId.
     */
    protected function saveFlotillaWithCrew(string $eventId, string $boatKey, array $crewKeys): void
    {
        $this->seasonRepository->saveFlotilla(EventId::fromString($eventId), [
            'event_id' => $eventId,
            'crewed_boats' => [
                [
                    'boat' => ['key' => $boatKey],
                    'crews' => array_map(fn($k) => ['key' => $k], $crewKeys),
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ]);
    }

    // ==================== HAPPY PATH TESTS ====================

    public function testFlagsSingleCrewOnce(): void
    {
        $ownerId = $this->createTestUser('owner1@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew1@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, [$crewKey]);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals($crewKey, $results[0]['crew_key']);
        $this->assertEquals(1, $results[0]['flag_count']);
        $this->assertEquals(1, $results[0]['rank_commitment']);
        $this->assertEquals(1, $this->getCommitmentRank($crewKey));
    }

    public function testFlagsSameCrewAcrossTwoEventsDecrementsByTwo(): void
    {
        $ownerId = $this->createTestUser('owner2@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew2@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, [$crewKey]);
        $this->saveFlotillaWithCrew('Fri May 22', $boatKey, [$crewKey]);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
            ['eventId' => 'Fri May 22', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]['flag_count']);
        $this->assertEquals(0, $results[0]['rank_commitment']);
        $this->assertEquals(0, $this->getCommitmentRank($crewKey));
    }

    public function testDecrementClampedAtZero(): void
    {
        $ownerId = $this->createTestUser('owner3@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew3@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 1]);

        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, [$crewKey]);
        $this->saveFlotillaWithCrew('Fri May 22', $boatKey, [$crewKey]);

        // Starting rank 1, flagged twice (would be -1 unclamped) -> clamped to 0
        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
            ['eventId' => 'Fri May 22', 'crewKey' => $crewKey],
        ]);

        $this->assertEquals(0, $results[0]['rank_commitment']);
        $this->assertEquals(0, $this->getCommitmentRank($crewKey));
    }

    public function testDuplicatePairInSameRequestCountsOnce(): void
    {
        $ownerId = $this->createTestUser('owner4@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew4@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, [$crewKey]);

        // Same (eventId, crewKey) pair submitted 3 times — should only count once
        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
        ]);

        $this->assertEquals(1, $results[0]['flag_count']);
        $this->assertEquals(1, $this->getCommitmentRank($crewKey));
    }

    public function testMultipleCrewFlaggedInOneRequestEachGetsOwnCount(): void
    {
        $ownerId = $this->createTestUser('owner5@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);

        $crewUserId1 = $this->createTestUser('crew5a@example.com', 'crew');
        $crewKey1 = $this->createCrewProfileForUser($crewUserId1, ['key' => 'crew5a', 'commitmentRank' => 2]);

        $crewUserId2 = $this->createTestUser('crew5b@example.com', 'crew');
        $crewKey2 = $this->createCrewProfileForUser($crewUserId2, ['key' => 'crew5b', 'commitmentRank' => 2]);

        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, [$crewKey1, $crewKey2]);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey1],
        ]);

        // Only crew1 was flagged; crew2 must not appear or be decremented
        $this->assertCount(1, $results);
        $this->assertEquals($crewKey1, $results[0]['crew_key']);
        $this->assertEquals(1, $this->getCommitmentRank($crewKey1));
        $this->assertEquals(2, $this->getCommitmentRank($crewKey2));
    }

    // ==================== SECURITY / VERIFICATION TESTS ====================

    public function testCrewNotActuallyAssignedIsIgnored(): void
    {
        $ownerId = $this->createTestUser('owner6@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew6@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        // Flotilla exists for the event, but this crew is NOT on the boat
        $this->saveFlotillaWithCrew('Fri May 15', $boatKey, []);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(0, $results);
        $this->assertEquals(2, $this->getCommitmentRank($crewKey));
    }

    public function testCrewAssignedToDifferentBoatIsIgnored(): void
    {
        $ownerId = $this->createTestUser('owner7@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);

        $otherOwnerId = $this->createTestUser('owner7b@example.com', 'boat_owner');
        $otherBoatKey = $this->createBoatProfileForUser($otherOwnerId, ['key' => 'boat_other']);

        $crewUserId = $this->createTestUser('crew7@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        // Crew is assigned to the OTHER owner's boat, not this owner's boat
        $this->saveFlotillaWithCrew('Fri May 15', $otherBoatKey, [$crewKey]);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 15', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(0, $results);
        $this->assertEquals(2, $this->getCommitmentRank($crewKey));
    }

    public function testNonExistentEventIsIgnored(): void
    {
        $ownerId = $this->createTestUser('owner8@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew8@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Nonexistent Event', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(0, $results);
        $this->assertEquals(2, $this->getCommitmentRank($crewKey));
    }

    public function testEventWithNoFlotillaIsIgnored(): void
    {
        $ownerId = $this->createTestUser('owner9@example.com', 'boat_owner');
        $boatKey = $this->createBoatProfileForUser($ownerId);
        $crewUserId = $this->createTestUser('crew9@example.com', 'crew');
        $crewKey = $this->createCrewProfileForUser($crewUserId, ['commitmentRank' => 2]);

        // Event exists, but no flotilla has been generated for it yet
        $results = $this->useCase->execute($ownerId, [
            ['eventId' => 'Fri May 22', 'crewKey' => $crewKey],
        ]);

        $this->assertCount(0, $results);
        $this->assertEquals(2, $this->getCommitmentRank($crewKey));
    }

    // ==================== ERROR CONDITION TESTS ====================

    public function testBoatNotFoundThrowsException(): void
    {
        $nonExistentUserId = 99999;

        $this->expectException(BoatNotFoundException::class);

        $this->useCase->execute($nonExistentUserId, [
            ['eventId' => 'Fri May 15', 'crewKey' => 'someone'],
        ]);
    }

    public function testEmptyFlagsArrayReturnsEmptyResults(): void
    {
        $ownerId = $this->createTestUser('owner10@example.com', 'boat_owner');
        $this->createBoatProfileForUser($ownerId);

        $results = $this->useCase->execute($ownerId, []);

        $this->assertCount(0, $results);
    }
}
