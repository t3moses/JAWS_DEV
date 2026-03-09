<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Event;

use App\Application\UseCase\Event\GetAllEventsUseCase;
use App\Application\UseCase\Event\GetEventUseCase;
use App\Application\Exception\EventNotFoundException;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Service\SystemTimeService;
use App\Domain\ValueObject\EventId;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Event UseCase operations
 *
 * Tests event retrieval and querying functionality.
 */
class EventUseCaseTest extends IntegrationTestCase
{
    private GetAllEventsUseCase $getAllEventsUseCase;
    private GetEventUseCase $getEventUseCase;
    private EventRepository $eventRepository;
    private SeasonRepository $seasonRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seasonRepository = new SeasonRepository();
        $this->eventRepository = new EventRepository(new SystemTimeService($this->seasonRepository));
        
        $this->getAllEventsUseCase = new GetAllEventsUseCase($this->eventRepository);
        $this->getEventUseCase = new GetEventUseCase(
            $this->eventRepository,
            $this->seasonRepository
        );
        
        // Initialize test events
        $this->initializeTestData();
    }

    protected function initializeTestData(): void
    {
        // Insert test events
        $events = [
            'Fri May 15' => '2026-05-15',
            'Fri May 22' => '2026-05-22',
            'Fri May 29' => '2026-05-29',
        ];

        foreach ($events as $eventId => $date) {
            $this->pdo->exec("
                INSERT INTO events (event_id, event_date, start_time, finish_time, status)
                VALUES ('$eventId', '$date', '12:45:00', '17:00:00', 'upcoming')
            ");
        }
    }

    public function testGetAllEventsReturnsEmptyArrayWhenNoEvents(): void
    {
        // Clear test events
        $this->pdo->exec("DELETE FROM events");
        $events = $this->getAllEventsUseCase->execute();

        $this->assertIsArray($events);
        $this->assertEmpty($events);
    }

    public function testGetAllEventsReturnsSingleEvent(): void
    {
        $this->pdo->exec("DELETE FROM events");
        $this->pdo->exec("
            INSERT INTO events (event_id, event_date, start_time, finish_time, status)
            VALUES ('Test Event', '2026-05-01', '10:00:00', '15:00:00', 'upcoming')
        ");
        
        $events = $this->getAllEventsUseCase->execute();

        $this->assertCount(1, $events);
        $this->assertEquals('Test Event', $events[0]->eventId);
    }

    public function testGetAllEventsReturnsMultipleEvents(): void
    {
        // setUp() initializes 3 events
        $events = $this->getAllEventsUseCase->execute();

        $this->assertCount(3, $events);
    }

    public function testGetAllEventsReturnsEventData(): void
    {
        // setUp() initializes 3 events
        $events = $this->getAllEventsUseCase->execute();

        $this->assertCount(3, $events);
        $firstEvent = $events[0];
        
        $this->assertNotNull($firstEvent->eventId);
        $this->assertNotNull($firstEvent->startTime);
        $this->assertNotNull($firstEvent->finishTime);
        $this->assertEquals('upcoming', $firstEvent->status);
    }

    public function testGetEventReturnsEventById(): void
    {
        $eventId = EventId::fromString('Fri May 15');
        $result = $this->getEventUseCase->execute($eventId->toString());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('event', $result);
        $this->assertNotNull($result['event']);
        $this->assertEquals('Fri May 15', $result['event']->eventId);
    }

    public function testGetEventWithInvalidIdThrowsException(): void
    {
        $this->expectException(EventNotFoundException::class);
        
        $eventId = EventId::fromString('Non Existent Event');
        $this->getEventUseCase->execute($eventId->toString());
    }

    public function testGetEventWithDeletedEventThrowsException(): void
    {
        $this->pdo->exec("DELETE FROM events WHERE event_id = 'Fri May 15'");
        
        $this->expectException(EventNotFoundException::class);
        
        $eventId = EventId::fromString('Fri May 15');
        $this->getEventUseCase->execute($eventId->toString());
    }

    public function testGetAllEventsIncludesEventTimestamps(): void
    {
        $events = $this->getAllEventsUseCase->execute();

        foreach ($events as $event) {
            $this->assertNotNull($event->startTime);
            $this->assertNotNull($event->finishTime);
        }
    }

    public function testGetAllEventsOrderedByStartTime(): void
    {
        $events = $this->getAllEventsUseCase->execute();

        // Verify events are ordered by date
        for ($i = 1; $i < count($events); $i++) {
            $this->assertLessThanOrEqual(
                strtotime($events[$i]->date),
                strtotime($events[$i - 1]->date)
            );
        }
    }
}
