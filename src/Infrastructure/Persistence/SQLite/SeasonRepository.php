<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\TimeSource;
use PDO;

/**
 * SQLite Season Repository
 *
 * Implements season configuration and flotilla persistence using SQLite database.
 */
class SeasonRepository implements SeasonRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    public function getConfig(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM season_config WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();

        if ($row === false) {
            throw new \RuntimeException('Season configuration not found');
        }

        return $row;
    }

    public function updateConfig(array $config): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE season_config SET
                year = :year,
                source = :source,
                simulated_date = :simulated_date,
                start_time = :start_time,
                finish_time = :finish_time,
                blackout_from = :blackout_from,
                blackout_to = :blackout_to
            WHERE id = 1
        ');
        $stmt->execute([
            'year' => $config['year'] ?? date('Y'),
            'source' => $config['source'] ?? 'production',
            'simulated_date' => $config['simulated_date'] ?? null,
            'start_time' => $config['start_time'] ?? '12:45:00',
            'finish_time' => $config['finish_time'] ?? '17:00:00',
            'blackout_from' => $config['blackout_from'] ?? '10:00:00',
            'blackout_to' => $config['blackout_to'] ?? '18:00:00',
        ]);
    }

    public function getYear(): int
    {
        $config = $this->getConfig();
        return (int)$config['year'];
    }

    public function getTimeSource(): TimeSource
    {
        $config = $this->getConfig();
        return TimeSource::from($config['source']);
    }

    public function getSimulatedDate(): ?\DateTimeInterface
    {
        $config = $this->getConfig();
        if ($config['simulated_date'] === null) {
            return null;
        }
        return new \DateTimeImmutable($config['simulated_date']);
    }

    public function setTimeSource(TimeSource $source, ?\DateTimeInterface $simulatedDate = null): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE season_config SET
                source = :source,
                simulated_date = :simulated_date
            WHERE id = 1
        ');
        $stmt->execute([
            'source' => $source->value,
            'simulated_date' => $simulatedDate?->format('Y-m-d H:i:s'),
        ]);
    }

    public function saveFlotilla(EventId $eventId, array $flotillaData): void
    {
        $encoded = json_encode($flotillaData);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode flotilla data: ' . json_last_error_msg());
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO flotillas (event_id, flotilla_data)
            VALUES (:event_id, :flotilla_data)
            ON CONFLICT(event_id) DO UPDATE SET
                flotilla_data = :flotilla_data,
                generated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            'event_id' => $eventId->toString(),
            'flotilla_data' => $encoded,
        ]);
    }

    public function getFlotilla(EventId $eventId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT flotilla_data FROM flotillas WHERE event_id = :event_id LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId->toString()]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return json_decode($row['flotilla_data'], true);
    }

    public function deleteFlotilla(EventId $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM flotillas WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId->toString()]);
    }

    public function flotillaExists(EventId $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM flotillas WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId->toString()]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
