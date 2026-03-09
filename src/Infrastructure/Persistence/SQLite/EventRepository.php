<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Service\TimeServiceInterface;
use App\Domain\ValueObject\EventId;
use PDO;

/**
 * SQLite Event Repository
 *
 * Implements event persistence using SQLite database.
 */
class EventRepository implements EventRepositoryInterface
{
    private PDO $pdo;
    private TimeServiceInterface $timeService;

    public function __construct(TimeServiceInterface $timeService)
    {
        $this->pdo = Connection::getInstance();
        $this->timeService = $timeService;
    }

    public function findById(EventId $eventId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM events WHERE event_id = :event_id LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId->toString()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('
            SELECT event_id FROM events ORDER BY event_date, start_time
        ');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findPastEvents(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id FROM events
            WHERE event_date < :today OR (event_date = :today AND finish_time < :current_time)
            ORDER BY event_date, start_time
        ');
        $stmt->execute([
            'today' => $this->timeService->today()->format('Y-m-d'),
            'current_time' => $this->timeService->now()->format('H:i:s'),
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findFutureEvents(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id FROM events
            WHERE event_date > :today OR (event_date = :today AND start_time > :current_time)
            ORDER BY event_date, start_time
        ');
        $stmt->execute([
            'today' => $this->timeService->today()->format('Y-m-d'),
            'current_time' => $this->timeService->now()->format('H:i:s'),
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findNextEvent(): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id FROM events
            WHERE event_date > :today OR (event_date = :today AND start_time > :current_time)
            ORDER BY event_date, start_time
            LIMIT 1
        ');
        $stmt->execute([
            'today' => $this->timeService->today()->format('Y-m-d'),
            'current_time' => $this->timeService->now()->format('H:i:s'),
        ]);
        $result = $stmt->fetchColumn();
        return $result === false ? null : $result;
    }

    public function findLastEvent(): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id FROM events
            WHERE event_date < :today OR (event_date = :today AND finish_time < :current_time)
            ORDER BY event_date DESC, start_time DESC
            LIMIT 1
        ');
        $stmt->execute([
            'today' => $this->timeService->today()->format('Y-m-d'),
            'current_time' => $this->timeService->now()->format('H:i:s'),
        ]);
        $result = $stmt->fetchColumn();
        return $result === false ? null : $result;
    }

    public function create(EventId $eventId, \DateTimeInterface $date, string $startTime, string $finishTime): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO events (event_id, event_date, start_time, finish_time)
            VALUES (:event_id, :event_date, :start_time, :finish_time)
        ');
        $stmt->execute([
            'event_id' => $eventId->toString(),
            'event_date' => $date->format('Y-m-d'),
            'start_time' => $startTime,
            'finish_time' => $finishTime,
        ]);
    }

    public function delete(EventId $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM events WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId->toString()]);
    }

    public function exists(EventId $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId->toString()]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM events');
        return (int)$stmt->fetchColumn();
    }

    public function hasEventOnDate(\DateTimeImmutable $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE event_date = :date');
        $stmt->execute(['date' => $date->format('Y-m-d')]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function getEventDateMap(): array
    {
        $stmt = $this->pdo->query('
            SELECT event_id, event_date FROM events ORDER BY event_date, start_time
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['event_id']] = $row['event_date'];
        }

        return $map;
    }
}
