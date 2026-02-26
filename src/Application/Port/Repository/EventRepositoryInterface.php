<?php

declare(strict_types=1);

namespace App\Application\Port\Repository;

use App\Domain\ValueObject\EventId;

/**
 * Event Repository Interface
 *
 * Defines the contract for event data persistence.
 * Implementations handle database operations for season events.
 */
interface EventRepositoryInterface
{
    /**
     * Find an event by ID
     *
     * @param EventId $eventId
     * @return array<string, mixed>|null Event data or null if not found
     */
    public function findById(EventId $eventId): ?array;

    /**
     * Get all events for the season
     *
     * @return array<string> Array of event ID strings
     */
    public function findAll(): array;

    /**
     * Get past events (before current time)
     *
     * @return array<string> Array of event ID strings
     */
    public function findPastEvents(): array;

    /**
     * Get future events (after current time)
     *
     * @return array<string> Array of event ID strings
     */
    public function findFutureEvents(): array;

    /**
     * Get the next upcoming event
     *
     * @return string|null Event ID string or null if no future events
     */
    public function findNextEvent(): ?string;

    /**
     * Get the last past event
     *
     * @return string|null Event ID string or null if no past events
     */
    public function findLastEvent(): ?string;

    /**
     * Create an event
     *
     * @param EventId $eventId
     * @param \DateTimeInterface $date
     * @param string $startTime
     * @param string $finishTime
     * @return void
     */
    public function create(EventId $eventId, \DateTimeInterface $date, string $startTime, string $finishTime): void;

    /**
     * Delete an event
     *
     * @param EventId $eventId
     * @return void
     */
    public function delete(EventId $eventId): void;

    /**
     * Check if an event exists
     *
     * @param EventId $eventId
     * @return bool
     */
    public function exists(EventId $eventId): bool;

    /**
     * Get event count
     *
     * @return int
     */
    public function count(): int;

    /**
     * Get mapping of event IDs to event dates
     *
     * @return array<string, string> Map of event_id => event_date (ISO format)
     */
    public function getEventDateMap(): array;

    /**
     * Check whether any event is scheduled on a given date
     *
     * @param \DateTimeImmutable $date
     * @return bool
     */
    public function hasEventOnDate(\DateTimeImmutable $date): bool;
}
