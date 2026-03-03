<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Domain\Entity\Boat;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;
use PDO;

/**
 * SQLite Boat Repository
 *
 * Implements boat persistence using SQLite database.
 */
class BoatRepository implements BoatRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    public function findByKey(BoatKey $key): ?Boat
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM boats WHERE key = :key LIMIT 1
        ');
        $stmt->execute(['key' => $key->toString()]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByOwnerName(string $firstName, string $lastName): ?Boat
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM boats
            WHERE owner_first_name = :first_name
            AND owner_last_name = :last_name
            LIMIT 1
        ');
        $stmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByOwnerUserId(int $userId): ?Boat
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM boats WHERE owner_user_id = :owner_user_id LIMIT 1
        ');
        $stmt->execute(['owner_user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM boats ORDER BY display_name');
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Batch load all related data to avoid N+1 queries
        $boatIds = array_column($rows, 'id');
        $allAvailability = $this->batchLoadAvailability($boatIds);
        $allHistory = $this->batchLoadHistory($boatIds);

        // Hydrate boats with pre-loaded data
        $boats = [];
        foreach ($rows as $row) {
            $boat = $this->hydrateWithData(
                $row,
                $allAvailability[$row['id']] ?? [],
                $allHistory[$row['id']] ?? []
            );
            $boats[] = $boat;
        }

        return $boats;
    }

    public function findAvailableForEvent(EventId $eventId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT b.* FROM boats b
            INNER JOIN boat_availability ba ON b.id = ba.boat_id
            WHERE ba.event_id = :event_id AND ba.berths > 0
            ORDER BY b.display_name
        ');
        $stmt->execute(['event_id' => $eventId->toString()]);
        $rows = $stmt->fetchAll();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function save(Boat $boat): void
    {
        if ($boat->getId() === null) {
            $this->insert($boat);
        } else {
            $this->update($boat);
        }
    }

    public function delete(BoatKey $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM boats WHERE key = :key');
        $stmt->execute(['key' => $key->toString()]);
    }

    public function exists(BoatKey $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM boats WHERE key = :key');
        $stmt->execute(['key' => $key->toString()]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function updateAvailability(BoatKey $key, EventId $eventId, int $berths): void
    {
        $boat = $this->findByKey($key);
        if ($boat === null) {
            throw new \RuntimeException("Boat not found: {$key->toString()}");
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO boat_availability (boat_id, event_id, berths)
            VALUES (:boat_id, :event_id, :berths)
            ON CONFLICT(boat_id, event_id) DO UPDATE SET berths = :berths
        ');
        $stmt->execute([
            'boat_id' => $boat->getId(),
            'event_id' => $eventId->toString(),
            'berths' => $berths,
        ]);
    }

    public function updateHistory(BoatKey $key, EventId $eventId, string $participated): void
    {
        $boat = $this->findByKey($key);
        if ($boat === null) {
            throw new \RuntimeException("Boat not found: {$key->toString()}");
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO boat_history (boat_id, event_id, participated)
            VALUES (:boat_id, :event_id, :participated)
            ON CONFLICT(boat_id, event_id) DO UPDATE SET participated = :participated
        ');
        $stmt->execute([
            'boat_id' => $boat->getId(),
            'event_id' => $eventId->toString(),
            'participated' => $participated,
        ]);
    }

    public function updateRankFlexibility(Boat $boat): void
    {
        if ($boat->getId() === null) {
            // Boat not yet persisted, skip update
            return;
        }

        $rank = $boat->getRank();
        $stmt = $this->pdo->prepare('
            UPDATE boats
            SET rank_flexibility = :rank_flexibility
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $boat->getId(),
            'rank_flexibility' => $rank->getDimension(BoatRankDimension::FLEXIBILITY),
        ]);
    }

    public function updateRankAbsence(Boat $boat): void
    {
        if ($boat->getId() === null) {
            return;
        }

        $rank = $boat->getRank();
        $stmt = $this->pdo->prepare('
            UPDATE boats
            SET rank_absence = :rank_absence
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $boat->getId(),
            'rank_absence' => $rank->getDimension(BoatRankDimension::ABSENCE),
        ]);
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM boats');
        return (int)$stmt->fetchColumn();
    }

    public function displayNameExists(string $displayName): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM boats WHERE display_name = :display_name');
        $stmt->execute(['display_name' => $displayName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Insert new boat
     */
    private function insert(Boat $boat): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO boats (
                key, display_name, owner_first_name, owner_last_name,
                owner_mobile, min_berths, max_berths,
                assistance_required, social_preference,
                rank_flexibility, rank_absence,
                owner_user_id
            ) VALUES (
                :key, :display_name, :owner_first_name, :owner_last_name,
                :owner_mobile, :min_berths, :max_berths,
                :assistance_required, :social_preference,
                :rank_flexibility, :rank_absence,
                :owner_user_id
            )
        ');

        $rank = $boat->getRank();
        $stmt->execute([
            'key' => $boat->getKey()->toString(),
            'display_name' => $boat->getDisplayName(),
            'owner_first_name' => $boat->getOwnerFirstName(),
            'owner_last_name' => $boat->getOwnerLastName(),
            'owner_mobile' => $boat->getOwnerMobile(),
            'min_berths' => $boat->getMinBerths(),
            'max_berths' => $boat->getMaxBerths(),
            'assistance_required' => $boat->requiresAssistance() ? 'Yes' : 'No',
            'social_preference' => $boat->hasSocialPreference() ? 'Yes' : 'No',
            'rank_flexibility' => $rank->getDimension(BoatRankDimension::FLEXIBILITY),
            'rank_absence' => $rank->getDimension(BoatRankDimension::ABSENCE),
            'owner_user_id' => $boat->getOwnerUserId(),
        ]);

        $boat->setId((int)$this->pdo->lastInsertId());

        // Insert availability and history
        $this->saveAvailabilityAndHistory($boat);
    }

    /**
     * Update existing boat
     */
    private function update(Boat $boat): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE boats SET
                display_name = :display_name,
                owner_first_name = :owner_first_name,
                owner_last_name = :owner_last_name,
                owner_mobile = :owner_mobile,
                min_berths = :min_berths,
                max_berths = :max_berths,
                assistance_required = :assistance_required,
                social_preference = :social_preference,
                rank_flexibility = :rank_flexibility,
                rank_absence = :rank_absence,
                owner_user_id = :owner_user_id
            WHERE id = :id
        ');

        $rank = $boat->getRank();
        $stmt->execute([
            'id' => $boat->getId(),
            'display_name' => $boat->getDisplayName(),
            'owner_first_name' => $boat->getOwnerFirstName(),
            'owner_last_name' => $boat->getOwnerLastName(),
            'owner_mobile' => $boat->getOwnerMobile(),
            'min_berths' => $boat->getMinBerths(),
            'max_berths' => $boat->getMaxBerths(),
            'assistance_required' => $boat->requiresAssistance() ? 'Yes' : 'No',
            'social_preference' => $boat->hasSocialPreference() ? 'Yes' : 'No',
            'rank_flexibility' => $rank->getDimension(BoatRankDimension::FLEXIBILITY),
            'rank_absence' => $rank->getDimension(BoatRankDimension::ABSENCE),
            'owner_user_id' => $boat->getOwnerUserId(),
        ]);

        // Update availability and history
        $this->saveAvailabilityAndHistory($boat);
    }

    /**
     * Save boat availability and history
     *
     * Optimized to avoid redundant findByKey() lookups since we already have the boat ID
     */
    private function saveAvailabilityAndHistory(Boat $boat): void
    {
        $boatId = $boat->getId();

        // Save availability (berths) - direct insert without lookup
        $berths = $boat->getAllBerths();
        if (!empty($berths)) {
            $stmt = $this->pdo->prepare('
                INSERT INTO boat_availability (boat_id, event_id, berths)
                VALUES (:boat_id, :event_id, :berths)
                ON CONFLICT(boat_id, event_id) DO UPDATE SET berths = :berths
            ');
            foreach ($berths as $eventIdString => $berthCount) {
                $stmt->execute([
                    'boat_id' => $boatId,
                    'event_id' => $eventIdString,
                    'berths' => $berthCount,
                ]);
            }
        }

        // Save history - direct insert without lookup
        $history = $boat->getAllHistory();
        if (!empty($history)) {
            $stmt = $this->pdo->prepare('
                INSERT INTO boat_history (boat_id, event_id, participated)
                VALUES (:boat_id, :event_id, :participated)
                ON CONFLICT(boat_id, event_id) DO UPDATE SET participated = :participated
            ');
            foreach ($history as $eventIdString => $participated) {
                $stmt->execute([
                    'boat_id' => $boatId,
                    'event_id' => $eventIdString,
                    'participated' => $participated,
                ]);
            }
        }
    }

    /**
     * Hydrate boat entity from database row
     */
    private function hydrate(array $row): Boat
    {
        $boat = new Boat(
            key: BoatKey::fromString($row['key']),
            displayName: $row['display_name'] ?? null,
            ownerFirstName: $row['owner_first_name'],
            ownerLastName: $row['owner_last_name'],
            ownerMobile: $row['owner_mobile'] ?? '',
            minBerths: (int)$row['min_berths'],
            maxBerths: (int)$row['max_berths'],
            assistanceRequired: $row['assistance_required'] === 'Yes',
            socialPreference: $row['social_preference'] === 'Yes',
        );

        $boat->setId((int)$row['id']);

        // Set owner_user_id if present
        if (isset($row['owner_user_id']) && $row['owner_user_id'] !== null) {
            $boat->setOwnerUserId((int)$row['owner_user_id']);
        }

        // Set rank
        $rank = Rank::forBoat(
            flexibility: (int)$row['rank_flexibility'],
            absence: (int)$row['rank_absence']
        );
        $boat->setRank($rank);

        // Load availability
        $this->loadAvailability($boat);

        // Load history
        $this->loadHistory($boat);

        return $boat;
    }

    /**
     * Load boat availability from database
     */
    private function loadAvailability(Boat $boat): void
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id, berths FROM boat_availability WHERE boat_id = :boat_id
        ');
        $stmt->execute(['boat_id' => $boat->getId()]);

        while ($row = $stmt->fetch()) {
            $boat->setBerths(
                EventId::fromString($row['event_id']),
                (int)$row['berths']
            );
        }
    }

    /**
     * Load boat history from database
     */
    private function loadHistory(Boat $boat): void
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id, participated FROM boat_history WHERE boat_id = :boat_id
        ');
        $stmt->execute(['boat_id' => $boat->getId()]);

        while ($row = $stmt->fetch()) {
            $boat->setHistory(
                EventId::fromString($row['event_id']),
                $row['participated']
            );
        }
    }

    /**
     * Batch load availability for multiple boats (avoids N+1 queries)
     *
     * @param array<int> $boatIds
     * @return array<int, array<string, int>> Boat ID => [event_id => berths]
     */
    private function batchLoadAvailability(array $boatIds): array
    {
        if (empty($boatIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($boatIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT boat_id, event_id, berths
            FROM boat_availability
            WHERE boat_id IN ($placeholders)
        ");
        $stmt->execute($boatIds);

        $result = [];
        while ($row = $stmt->fetch()) {
            $boatId = (int)$row['boat_id'];
            if (!isset($result[$boatId])) {
                $result[$boatId] = [];
            }
            $result[$boatId][$row['event_id']] = (int)$row['berths'];
        }

        return $result;
    }

    /**
     * Batch load history for multiple boats (avoids N+1 queries)
     *
     * @param array<int> $boatIds
     * @return array<int, array<string, string>> Boat ID => [event_id => participated]
     */
    private function batchLoadHistory(array $boatIds): array
    {
        if (empty($boatIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($boatIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT boat_id, event_id, participated
            FROM boat_history
            WHERE boat_id IN ($placeholders)
        ");
        $stmt->execute($boatIds);

        $result = [];
        while ($row = $stmt->fetch()) {
            $boatId = (int)$row['boat_id'];
            if (!isset($result[$boatId])) {
                $result[$boatId] = [];
            }
            $result[$boatId][$row['event_id']] = $row['participated'];
        }

        return $result;
    }

    /**
     * Hydrate boat entity with pre-loaded data (optimized for batch loading)
     *
     * @param array<string, mixed> $row Database row
     * @param array<string, int> $availability Event ID => berths
     * @param array<string, string> $history Event ID => participated
     */
    private function hydrateWithData(array $row, array $availability, array $history): Boat
    {
        $boat = new Boat(
            key: BoatKey::fromString($row['key']),
            displayName: $row['display_name'] ?? null,
            ownerFirstName: $row['owner_first_name'],
            ownerLastName: $row['owner_last_name'],
            ownerMobile: $row['owner_mobile'] ?? '',
            minBerths: (int)$row['min_berths'],
            maxBerths: (int)$row['max_berths'],
            assistanceRequired: $row['assistance_required'] === 'Yes',
            socialPreference: $row['social_preference'] === 'Yes',
        );

        $boat->setId((int)$row['id']);

        // Set owner_user_id if present
        if (isset($row['owner_user_id']) && $row['owner_user_id'] !== null) {
            $boat->setOwnerUserId((int)$row['owner_user_id']);
        }

        // Set rank
        $rank = Rank::forBoat(
            flexibility: (int)$row['rank_flexibility'],
            absence: (int)$row['rank_absence']
        );
        $boat->setRank($rank);

        // Set pre-loaded availability
        foreach ($availability as $eventIdString => $berths) {
            $boat->setBerths(EventId::fromString($eventIdString), $berths);
        }

        // Set pre-loaded history
        foreach ($history as $eventIdString => $participated) {
            $boat->setHistory(EventId::fromString($eventIdString), $participated);
        }

        return $boat;
    }
}
