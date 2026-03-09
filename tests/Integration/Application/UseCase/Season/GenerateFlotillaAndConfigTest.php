<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Season;

use App\Application\UseCase\Season\GenerateFlotillaUseCase;
use App\Application\UseCase\Season\UpdateConfigUseCase;
use App\Application\DTO\Request\UpdateConfigRequest;
use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\ValidationException;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Service\SystemTimeService;
use App\Domain\ValueObject\EventId;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Season UseCase operations
 *
 * Tests season management including:
 * - Generating flotilla data for events
 * - Updating season configuration
 */
class GenerateFlotillaAndConfigTest extends IntegrationTestCase
{
    private GenerateFlotillaUseCase $generateFlotillaUseCase;
    private UpdateConfigUseCase $updateConfigUseCase;
    private EventRepository $eventRepository;
    private SeasonRepository $seasonRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seasonRepository = new SeasonRepository();
        $this->eventRepository = new EventRepository(new SystemTimeService($this->seasonRepository));

        $this->generateFlotillaUseCase = new GenerateFlotillaUseCase(
            $this->eventRepository,
            $this->seasonRepository
        );

        $this->updateConfigUseCase = new UpdateConfigUseCase(
            $this->seasonRepository
        );

        $this->initializeTestData();
    }

    protected function initializeTestData(): void
    {
        // Create test events
        $this->createTestEvent('Fri May 15', '2026-05-15');
        $this->createTestEvent('Fri May 22', '2026-05-22');
    }

    // ==================== GenerateFlotillaUseCase Tests ====================

    public function testGenerateFlotillaThrowsExceptionWhenEventNotFound(): void
    {
        $this->expectException(EventNotFoundException::class);

        $eventId = EventId::fromString('Non Existent Event');
        $this->generateFlotillaUseCase->execute($eventId);
    }

    public function testGenerateFlotillaReturnsEmptyStructureWhenNoFlotilla(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->generateFlotillaUseCase->execute($eventId);

        $this->assertIsArray($result);
        $this->assertEquals('Fri May 15', $result['event_id']);
        $this->assertArrayHasKey('crewed_boats', $result);
        $this->assertArrayHasKey('waitlist_boats', $result);
        $this->assertArrayHasKey('waitlist_crews', $result);
        $this->assertEmpty($result['crewed_boats']);
        $this->assertEmpty($result['waitlist_boats']);
        $this->assertEmpty($result['waitlist_crews']);
    }

    public function testGenerateFlotillaReturnsExistingFlotilla(): void
    {
        $eventId = EventId::fromString('Fri May 15');

        // Create a sample flotilla
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
                            'key' => 'Test Crew',
                            'display_name' => 'Test Crew',
                            'skill' => 'intermediate',
                        ],
                    ],
                ],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        // Insert flotilla into database
        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES ('Fri May 15', '" . json_encode($flotilla) . "', CURRENT_TIMESTAMP)
        ");

        $result = $this->generateFlotillaUseCase->execute($eventId);

        $this->assertEquals('Fri May 15', $result['event_id']);
        $this->assertCount(1, $result['crewed_boats']);
        $this->assertEquals('Test Boat', $result['crewed_boats'][0]['boat']['display_name']);
    }

    public function testGenerateFlotillaIncludesCrewedBoats(): void
    {
        $eventId = EventId::fromString('Fri May 15');

        $flotilla = [
            'event_id' => 'Fri May 15',
            'crewed_boats' => [
                [
                    'boat' => ['key' => 'Boat 1', 'display_name' => 'Boat 1'],
                    'crews' => [
                        ['key' => 'Crew 1', 'display_name' => 'Crew 1', 'skill' => 'advanced'],
                        ['key' => 'Crew 2', 'display_name' => 'Crew 2', 'skill' => 'intermediate'],
                    ],
                ],
                [
                    'boat' => ['key' => 'Boat 2', 'display_name' => 'Boat 2'],
                    'crews' => [
                        ['key' => 'Crew 3', 'display_name' => 'Crew 3', 'skill' => 'novice'],
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

        $result = $this->generateFlotillaUseCase->execute($eventId);

        $this->assertCount(2, $result['crewed_boats']);
        $this->assertCount(2, $result['crewed_boats'][0]['crews']);
        $this->assertCount(1, $result['crewed_boats'][1]['crews']);
    }

    public function testGenerateFlotillaIncludesWaitlists(): void
    {
        $eventId = EventId::fromString('Fri May 15');

        $flotilla = [
            'event_id' => 'Fri May 15',
            'crewed_boats' => [],
            'waitlist_boats' => [
                ['key' => 'Waitlist Boat', 'display_name' => 'Waitlist Boat'],
            ],
            'waitlist_crews' => [
                ['key' => 'Waitlist Crew', 'display_name' => 'Waitlist Crew', 'skill' => 'intermediate'],
            ],
        ];

        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES ('Fri May 15', '" . json_encode($flotilla) . "', CURRENT_TIMESTAMP)
        ");

        $result = $this->generateFlotillaUseCase->execute($eventId);

        $this->assertCount(1, $result['waitlist_boats']);
        $this->assertCount(1, $result['waitlist_crews']);
        $this->assertEquals('Waitlist Boat', $result['waitlist_boats'][0]['display_name']);
        $this->assertEquals('Waitlist Crew', $result['waitlist_crews'][0]['display_name']);
    }

    public function testGenerateFlotillaForDifferentEvents(): void
    {
        $event1 = EventId::fromString('Fri May 15');
        $event2 = EventId::fromString('Fri May 22');

        // Create flotilla for event 1
        $flotilla1 = [
            'event_id' => 'Fri May 15',
            'crewed_boats' => [
                ['boat' => ['key' => 'Boat A', 'display_name' => 'Boat A'], 'crews' => []],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        // Create flotilla for event 2
        $flotilla2 = [
            'event_id' => 'Fri May 22',
            'crewed_boats' => [
                ['boat' => ['key' => 'Boat B', 'display_name' => 'Boat B'], 'crews' => []],
            ],
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];

        $this->pdo->exec("
            INSERT INTO flotillas (event_id, flotilla_data, generated_at)
            VALUES
                ('Fri May 15', '" . json_encode($flotilla1) . "', CURRENT_TIMESTAMP),
                ('Fri May 22', '" . json_encode($flotilla2) . "', CURRENT_TIMESTAMP)
        ");

        $result1 = $this->generateFlotillaUseCase->execute($event1);
        $result2 = $this->generateFlotillaUseCase->execute($event2);

        $this->assertEquals('Boat A', $result1['crewed_boats'][0]['boat']['display_name']);
        $this->assertEquals('Boat B', $result2['crewed_boats'][0]['boat']['display_name']);
    }

    // ==================== UpdateConfigUseCase Tests ====================

    public function testUpdateConfigUpdatesSource(): void
    {
        $request = new UpdateConfigRequest(source: 'production');
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('production', $config['source']);
    }

    public function testUpdateConfigUpdatesSimulatedDate(): void
    {
        $request = new UpdateConfigRequest(simulatedDate: '2026-06-01 09:00:00');
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('2026-06-01 09:00:00', $config['simulated_date']);
    }

    public function testUpdateConfigUpdatesYear(): void
    {
        $request = new UpdateConfigRequest(year: 2027);
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals(2027, $config['year']);
    }

    public function testUpdateConfigUpdatesStartTime(): void
    {
        $request = new UpdateConfigRequest(startTime: '13:00:00');
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('13:00:00', $config['start_time']);
    }

    public function testUpdateConfigUpdatesFinishTime(): void
    {
        $request = new UpdateConfigRequest(finishTime: '18:00:00');
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('18:00:00', $config['finish_time']);
    }

    public function testUpdateConfigUpdatesBlackoutWindow(): void
    {
        $request = new UpdateConfigRequest(
            blackoutFrom: '09:00:00',
            blackoutTo: '19:00:00'
        );
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('09:00:00', $config['blackout_from']);
        $this->assertEquals('19:00:00', $config['blackout_to']);
    }

    public function testUpdateConfigUpdatesMultipleFieldsAtOnce(): void
    {
        $request = new UpdateConfigRequest(
            source: 'production',
            year: 2027,
            startTime: '14:00:00',
            finishTime: '19:00:00'
        );
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertTrue($result['success']);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('production', $config['source']);
        $this->assertEquals(2027, $config['year']);
        $this->assertEquals('14:00:00', $config['start_time']);
        $this->assertEquals('19:00:00', $config['finish_time']);
    }

    public function testUpdateConfigThrowsValidationExceptionForInvalidSource(): void
    {
        $this->expectException(ValidationException::class);

        $request = new UpdateConfigRequest(source: 'invalid_source');
        $this->updateConfigUseCase->execute($request);
    }

    public function testUpdateConfigThrowsValidationExceptionForInvalidDate(): void
    {
        $this->expectException(ValidationException::class);

        $request = new UpdateConfigRequest(simulatedDate: 'invalid-date');
        $this->updateConfigUseCase->execute($request);
    }

    public function testUpdateConfigThrowsValidationExceptionForInvalidYear(): void
    {
        $this->expectException(ValidationException::class);

        $request = new UpdateConfigRequest(year: 1999);
        $this->updateConfigUseCase->execute($request);
    }

    public function testUpdateConfigThrowsValidationExceptionForInvalidTime(): void
    {
        $this->expectException(ValidationException::class);

        $request = new UpdateConfigRequest(startTime: '25:00:00');
        $this->updateConfigUseCase->execute($request);
    }

    public function testUpdateConfigAllowsPartialUpdates(): void
    {
        // Get initial config
        $initialConfig = $this->seasonRepository->getConfig();
        $initialYear = $initialConfig['year'];

        // Update only source
        $request = new UpdateConfigRequest(source: 'production');
        $this->updateConfigUseCase->execute($request);

        $config = $this->seasonRepository->getConfig();
        $this->assertEquals('production', $config['source']);
        $this->assertEquals($initialYear, $config['year']); // Unchanged
    }

    public function testUpdateConfigReturnsSuccessMessage(): void
    {
        $request = new UpdateConfigRequest(year: 2027);
        $result = $this->updateConfigUseCase->execute($request);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated', strtolower($result['message']));
    }
}
