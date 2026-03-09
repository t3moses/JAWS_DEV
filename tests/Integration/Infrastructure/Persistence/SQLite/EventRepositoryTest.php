<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence\SQLite;

use App\Application\Port\Service\TimeServiceInterface;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Domain\ValueObject\EventId;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for EventRepository
 *
 * Tests database operations for events including:
 * - CRUD operations
 * - Query filters (past, future, next, last)
 * - Event date mapping
 */
class EventRepositoryTest extends IntegrationTestCase
{
    private EventRepository $repository;
    private TimeServiceInterface $timeService;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a fixed simulated date so past/future tests are deterministic
        $this->timeService = $this->createMock(TimeServiceInterface::class);
        $this->timeService->method('today')->willReturn(new \DateTimeImmutable('2026-02-08'));
        $this->timeService->method('now')->willReturn(new \DateTimeImmutable('2026-02-08 09:00:00'));
        $this->repository = new EventRepository($this->timeService);
    }

    public function testFindByIdReturnsEventWhenExists(): void
    {
        // Create test event
        $eventId = EventId::fromString('2026-03-15');
        $date = new \DateTimeImmutable('2026-03-15');
        $this->repository->create($eventId, $date, '09:00:00', '17:00:00');

        // Test
        $result = $this->repository->findById($eventId);

        $this->assertNotNull($result);
        $this->assertEquals('2026-03-15', $result['event_id']);
        $this->assertEquals('2026-03-15', $result['event_date']);
        $this->assertEquals('09:00:00', $result['start_time']);
        $this->assertEquals('17:00:00', $result['finish_time']);
    }

    public function testFindByIdReturnsNullWhenNotExists(): void
    {
        $eventId = EventId::fromString('2026-12-31');

        $result = $this->repository->findById($eventId);

        $this->assertNull($result);
    }

    public function testCreateInsertsNewEvent(): void
    {
        $eventId = EventId::fromString('2026-04-10');
        $date = new \DateTimeImmutable('2026-04-10');

        $this->repository->create($eventId, $date, '10:30:00', '16:00:00');

        $result = $this->repository->findById($eventId);
        $this->assertNotNull($result);
        $this->assertEquals('2026-04-10', $result['event_id']);
        $this->assertEquals('10:30:00', $result['start_time']);
    }

    public function testDeleteRemovesEvent(): void
    {
        // Create event
        $eventId = EventId::fromString('2026-05-20');
        $date = new \DateTimeImmutable('2026-05-20');
        $this->repository->create($eventId, $date, '09:00:00', '17:00:00');

        // Verify it exists
        $this->assertTrue($this->repository->exists($eventId));

        // Delete
        $this->repository->delete($eventId);

        // Verify it's gone
        $this->assertFalse($this->repository->exists($eventId));
        $this->assertNull($this->repository->findById($eventId));
    }

    public function testExistsReturnsTrueForExistingEvent(): void
    {
        $eventId = EventId::fromString('2026-06-15');
        $date = new \DateTimeImmutable('2026-06-15');
        $this->repository->create($eventId, $date, '09:00:00', '17:00:00');

        $this->assertTrue($this->repository->exists($eventId));
    }

    public function testExistsReturnsFalseForNonExistentEvent(): void
    {
        $eventId = EventId::fromString('2026-12-31');

        $this->assertFalse($this->repository->exists($eventId));
    }

    public function testFindAllReturnsAllEventIds(): void
    {
        // Create multiple events
        $this->createEvent('2026-03-10');
        $this->createEvent('2026-03-15');
        $this->createEvent('2026-03-20');

        $result = $this->repository->findAll();

        $this->assertCount(3, $result);
        $this->assertContains('2026-03-10', $result);
        $this->assertContains('2026-03-15', $result);
        $this->assertContains('2026-03-20', $result);
    }

    public function testFindAllReturnsEventsInChronologicalOrder(): void
    {
        // Create events out of order
        $this->createEvent('2026-03-20');
        $this->createEvent('2026-03-10');
        $this->createEvent('2026-03-15');

        $result = $this->repository->findAll();

        // Should be ordered by date
        $this->assertEquals('2026-03-10', $result[0]);
        $this->assertEquals('2026-03-15', $result[1]);
        $this->assertEquals('2026-03-20', $result[2]);
    }

    public function testFindPastEventsReturnsOnlyPastEvents(): void
    {
        // Note: Today is February 8, 2026 based on context
        $this->createEvent('2026-01-15'); // Past
        $this->createEvent('2026-02-01'); // Past
        $this->createEvent('2026-03-15'); // Future
        $this->createEvent('2026-04-20'); // Future

        $result = $this->repository->findPastEvents();

        $this->assertCount(2, $result);
        $this->assertContains('2026-01-15', $result);
        $this->assertContains('2026-02-01', $result);
        $this->assertNotContains('2026-03-15', $result);
        $this->assertNotContains('2026-04-20', $result);
    }

    public function testFindFutureEventsReturnsOnlyFutureEvents(): void
    {
        // Note: Today is February 8, 2026
        $this->createEvent('2026-01-15'); // Past
        $this->createEvent('2026-02-01'); // Past
        $this->createEvent('2026-03-15'); // Future
        $this->createEvent('2026-04-20'); // Future

        $result = $this->repository->findFutureEvents();

        $this->assertCount(2, $result);
        $this->assertContains('2026-03-15', $result);
        $this->assertContains('2026-04-20', $result);
        $this->assertNotContains('2026-01-15', $result);
        $this->assertNotContains('2026-02-01', $result);
    }

    public function testFindNextEventReturnsClosestFutureEvent(): void
    {
        // Note: Today is February 8, 2026
        $this->createEvent('2026-01-15'); // Past
        $this->createEvent('2026-03-15'); // Future
        $this->createEvent('2026-04-20'); // Future (further)

        $result = $this->repository->findNextEvent();

        $this->assertEquals('2026-03-15', $result);
    }

    public function testFindNextEventReturnsNullWhenNoFutureEvents(): void
    {
        // Note: Today is February 8, 2026
        $this->createEvent('2026-01-15'); // Past
        $this->createEvent('2026-02-01'); // Past

        $result = $this->repository->findNextEvent();

        $this->assertNull($result);
    }

    public function testFindLastEventReturnsClosestPastEvent(): void
    {
        // Note: Today is February 8, 2026
        $this->createEvent('2026-01-15'); // Past (older)
        $this->createEvent('2026-02-01'); // Past (more recent)
        $this->createEvent('2026-03-15'); // Future

        $result = $this->repository->findLastEvent();

        $this->assertEquals('2026-02-01', $result);
    }

    public function testFindLastEventReturnsNullWhenNoPastEvents(): void
    {
        // Note: Today is February 8, 2026
        $this->createEvent('2026-03-15'); // Future
        $this->createEvent('2026-04-20'); // Future

        $result = $this->repository->findLastEvent();

        $this->assertNull($result);
    }

    public function testCountReturnsCorrectNumberOfEvents(): void
    {
        $this->assertEquals(0, $this->repository->count());

        $this->createEvent('2026-03-15');
        $this->assertEquals(1, $this->repository->count());

        $this->createEvent('2026-03-20');
        $this->assertEquals(2, $this->repository->count());

        $this->createEvent('2026-03-25');
        $this->assertEquals(3, $this->repository->count());
    }

    public function testGetEventDateMapReturnsCorrectMapping(): void
    {
        $this->createEvent('2026-03-15');
        $this->createEvent('2026-03-20');
        $this->createEvent('2026-04-10');

        $result = $this->repository->getEventDateMap();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('2026-03-15', $result);
        $this->assertArrayHasKey('2026-03-20', $result);
        $this->assertArrayHasKey('2026-04-10', $result);
        $this->assertEquals('2026-03-15', $result['2026-03-15']);
        $this->assertEquals('2026-03-20', $result['2026-03-20']);
        $this->assertEquals('2026-04-10', $result['2026-04-10']);
    }

    public function testEventsWithDifferentTimesOnSameDateAreOrderedByTime(): void
    {
        // Create events on same date with different times
        $eventId1 = EventId::fromString('2026-03-15-morning');
        $eventId2 = EventId::fromString('2026-03-15-afternoon');

        $date = new \DateTimeImmutable('2026-03-15');
        $this->repository->create($eventId2, $date, '14:00:00', '18:00:00'); // Create afternoon first
        $this->repository->create($eventId1, $date, '09:00:00', '12:00:00'); // Then morning

        $allEvents = $this->repository->findAll();

        // Morning event should come before afternoon event
        $morningIndex = array_search('2026-03-15-morning', $allEvents);
        $afternoonIndex = array_search('2026-03-15-afternoon', $allEvents);

        $this->assertNotFalse($morningIndex);
        $this->assertNotFalse($afternoonIndex);
        $this->assertLessThan($afternoonIndex, $morningIndex);
    }

    public function testCreateWithDateTimeInterface(): void
    {
        $eventId = EventId::fromString('2026-05-01');
        $date = new \DateTime('2026-05-01'); // Test with DateTime (not Immutable)

        $this->repository->create($eventId, $date, '10:00:00', '16:00:00');

        $result = $this->repository->findById($eventId);
        $this->assertNotNull($result);
        $this->assertEquals('2026-05-01', $result['event_date']);
    }

    public function testDeleteNonExistentEventDoesNotThrowError(): void
    {
        $eventId = EventId::fromString('2026-12-31');

        // Should not throw exception
        $this->repository->delete($eventId);

        $this->assertFalse($this->repository->exists($eventId));
    }

    // ==================== hasEventOnDate Tests ====================

    public function testHasEventOnDateReturnsTrueWhenEventExistsOnDate(): void
    {
        $this->createEvent('2026-05-15');

        $result = $this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15'));

        $this->assertTrue($result);
    }

    public function testHasEventOnDateReturnsFalseWhenNoEventOnDate(): void
    {
        $this->createEvent('2026-05-15');

        $result = $this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-16'));

        $this->assertFalse($result);
    }

    public function testHasEventOnDateReturnsFalseWhenNoEventsAtAll(): void
    {
        $result = $this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15'));

        $this->assertFalse($result);
    }

    public function testHasEventOnDateIgnoresTimeComponent(): void
    {
        // The method should match on date only, not datetime
        $this->createEvent('2026-05-15');

        $this->assertTrue($this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15 14:00:00')));
        $this->assertTrue($this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15 00:00:00')));
        $this->assertTrue($this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15 23:59:59')));
    }

    public function testHasEventOnDateMatchesOnlyExactDate(): void
    {
        $this->createEvent('2026-05-14');
        $this->createEvent('2026-05-16');

        // May 15 has no event even though adjacent days do
        $this->assertFalse($this->repository->hasEventOnDate(new \DateTimeImmutable('2026-05-15')));
    }

    public function testFindNextEventRespectsSimulatedDate(): void
    {
        // Create two events: one that would be "future" from Feb 8, one further in the future
        $this->createEvent('2026-03-15'); // Event A
        $this->createEvent('2026-06-20'); // Event B

        // From Feb 8 perspective, Event A is next
        $this->assertEquals('2026-03-15', $this->repository->findNextEvent());

        // Now simulate date as Apr 1 (after Event A but before Event B)
        $lateTimeService = $this->createMock(TimeServiceInterface::class);
        $lateTimeService->method('today')->willReturn(new \DateTimeImmutable('2026-04-01'));
        $lateTimeService->method('now')->willReturn(new \DateTimeImmutable('2026-04-01 09:00:00'));
        $repoWithLateDate = new EventRepository($lateTimeService);

        // Event A is now in the past; Event B should be next
        $this->assertEquals('2026-06-20', $repoWithLateDate->findNextEvent());
    }

    public function testFindFutureEventsRespectsSimulatedDate(): void
    {
        $this->createEvent('2026-03-15'); // Event A
        $this->createEvent('2026-06-20'); // Event B

        // From Feb 8: both are future
        $this->assertCount(2, $this->repository->findFutureEvents());

        // From Apr 1: only Event B is future
        $lateTimeService = $this->createMock(TimeServiceInterface::class);
        $lateTimeService->method('today')->willReturn(new \DateTimeImmutable('2026-04-01'));
        $lateTimeService->method('now')->willReturn(new \DateTimeImmutable('2026-04-01 09:00:00'));
        $repoWithLateDate = new EventRepository($lateTimeService);

        $future = $repoWithLateDate->findFutureEvents();
        $this->assertCount(1, $future);
        $this->assertContains('2026-06-20', $future);
        $this->assertNotContains('2026-03-15', $future);
    }

    // Helper method - different name to avoid conflict with base class
    private function createEvent(string $eventIdStr, string $startTime = '09:00:00', string $finishTime = '17:00:00'): void
    {
        $eventId = EventId::fromString($eventIdStr);
        $date = new \DateTimeImmutable($eventIdStr);
        $this->repository->create($eventId, $date, $startTime, $finishTime);
    }
}
