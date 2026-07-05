<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\CrewRankDimension;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use PDO;

/**
 * SQLite Crew Repository
 *
 * Implements crew persistence using SQLite database.
 */
class CrewRepository implements CrewRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    public function findByKey(CrewKey $key): ?Crew
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM crews WHERE key = :key LIMIT 1
        ');
        $stmt->execute(['key' => $key->toString()]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByName(string $firstName, string $lastName): ?Crew
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM crews
            WHERE first_name = :first_name
            AND last_name = :last_name
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

    public function findByUserId(int $userId): ?Crew
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM crews WHERE user_id = :user_id LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM crews ORDER BY display_name');
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Batch load all related data to avoid N+1 queries
        $crewIds = array_column($rows, 'id');
        $allAvailability = $this->batchLoadAvailability($crewIds);
        $allHistory = $this->batchLoadHistory($crewIds);
        $allWhitelist = $this->batchLoadWhitelist($crewIds);

        // Hydrate crews with pre-loaded data
        $crews = [];
        foreach ($rows as $row) {
            $crew = $this->hydrateWithData(
                $row,
                $allAvailability[$row['id']] ?? [],
                $allHistory[$row['id']] ?? [],
                $allWhitelist[$row['id']] ?? []
            );
            $crews[] = $crew;
        }

        return $crews;
    }

    public function findAvailableForEvent(EventId $eventId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.* FROM crews c
            INNER JOIN crew_availability ca ON c.id = ca.crew_id
            WHERE ca.event_id = :event_id AND ca.status IN (1, 2)
            ORDER BY c.display_name
        ');
        $stmt->execute(['event_id' => $eventId->toString()]);
        $rows = $stmt->fetchAll();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findAssignedToEvent(EventId $eventId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.* FROM crews c
            INNER JOIN crew_availability ca ON c.id = ca.crew_id
            WHERE ca.event_id = :event_id AND ca.status = 2
            ORDER BY c.display_name
        ');
        $stmt->execute(['event_id' => $eventId->toString()]);
        $rows = $stmt->fetchAll();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function save(Crew $crew): void
    {
        if ($crew->getId() === null) {
            $this->insert($crew);
        } else {
            $this->update($crew);
        }
    }

    public function delete(CrewKey $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM crews WHERE key = :key');
        $stmt->execute(['key' => $key->toString()]);
    }

    public function exists(CrewKey $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crews WHERE key = :key');
        $stmt->execute(['key' => $key->toString()]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function updateAvailability(CrewKey $key, EventId $eventId, AvailabilityStatus $status): void
    {
        $crew = $this->findByKey($key);
        if ($crew === null) {
            throw new \RuntimeException("Crew not found: {$key->toString()}");
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO crew_availability (crew_id, event_id, status)
            VALUES (:crew_id, :event_id, :status)
            ON CONFLICT(crew_id, event_id) DO UPDATE SET status = :status
        ');
        $stmt->execute([
            'crew_id' => $crew->getId(),
            'event_id' => $eventId->toString(),
            'status' => $status->value,
        ]);
    }

    public function updateHistory(CrewKey $key, EventId $eventId, string $boatKey): void
    {
        $crew = $this->findByKey($key);
        if ($crew === null) {
            throw new \RuntimeException("Crew not found: {$key->toString()}");
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO crew_history (crew_id, event_id, boat_key)
            VALUES (:crew_id, :event_id, :boat_key)
            ON CONFLICT(crew_id, event_id) DO UPDATE SET boat_key = :boat_key
        ');
        $stmt->execute([
            'crew_id' => $crew->getId(),
            'event_id' => $eventId->toString(),
            'boat_key' => $boatKey,
        ]);
    }

    public function addToWhitelist(CrewKey $crewKey, BoatKey $boatKey): void
    {
        $crew = $this->findByKey($crewKey);
        if ($crew === null) {
            throw new \RuntimeException("Crew not found: {$crewKey->toString()}");
        }

        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO crew_whitelist (crew_id, boat_key)
            VALUES (:crew_id, :boat_key)
        ');
        $stmt->execute([
            'crew_id' => $crew->getId(),
            'boat_key' => $boatKey->toString(),
        ]);
    }

    public function removeFromWhitelist(CrewKey $crewKey, BoatKey $boatKey): void
    {
        $crew = $this->findByKey($crewKey);
        if ($crew === null) {
            throw new \RuntimeException("Crew not found: {$crewKey->toString()}");
        }

        $stmt = $this->pdo->prepare('
            DELETE FROM crew_whitelist
            WHERE crew_id = :crew_id AND boat_key = :boat_key
        ');
        $stmt->execute([
            'crew_id' => $crew->getId(),
            'boat_key' => $boatKey->toString(),
        ]);
    }

    public function updateRankCommitment(Crew $crew): void
    {
        if ($crew->getId() === null) {
            // Crew not yet persisted, skip update
            return;
        }

        $rank = $crew->getRank();
        $stmt = $this->pdo->prepare('
            UPDATE crews
            SET commitment_rank = :commitment_rank
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $crew->getId(),
            'commitment_rank' => $rank->getDimension(CrewRankDimension::COMMITMENT),
        ]);
    }

    public function updateRankAbsence(Crew $crew): void
    {
        if ($crew->getId() === null) {
            return;
        }

        $rank = $crew->getRank();
        $stmt = $this->pdo->prepare('
            UPDATE crews
            SET rank_absence = :rank_absence
            WHERE id = :id
        ');
        $stmt->execute([
            'id'           => $crew->getId(),
            'rank_absence' => $rank->getDimension(CrewRankDimension::ABSENCE),
        ]);
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM crews');
        return (int)$stmt->fetchColumn();
    }

    public function displayNameExists(string $displayName): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crews WHERE display_name = :display_name');
        $stmt->execute(['display_name' => $displayName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Insert new crew
     */
    private function insert(Crew $crew): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO crews (
                key, display_name, first_name, last_name, partner_key,
                mobile, social_preference, membership_number,
                skill, experience,
                commitment_rank, rank_membership, rank_absence,
                user_id
            ) VALUES (
                :key, :display_name, :first_name, :last_name, :partner_key,
                :mobile, :social_preference, :membership_number,
                :skill, :experience,
                :commitment_rank, :rank_membership, :rank_absence,
                :user_id
            )
        ');

        $rank = $crew->getRank();
        $stmt->execute([
            'key' => $crew->getKey()->toString(),
            'display_name' => $crew->getDisplayName(),
            'first_name' => $crew->getFirstName(),
            'last_name' => $crew->getLastName(),
            'partner_key' => $crew->getPartnerKey()?->toString(),
            'mobile' => $crew->getMobile(),
            'social_preference' => $crew->hasSocialPreference() ? 'Yes' : 'No',
            'membership_number' => $crew->getMembershipNumber(),
            'skill' => $crew->getSkill()->value,
            'experience' => $crew->getExperience(),
            'commitment_rank' => $rank->getDimension(CrewRankDimension::COMMITMENT),
            'rank_membership' => $rank->getDimension(CrewRankDimension::MEMBERSHIP),
            'rank_absence' => $rank->getDimension(CrewRankDimension::ABSENCE),
            'user_id' => $crew->getUserId(),
        ]);

        $crew->setId((int)$this->pdo->lastInsertId());

        // Insert availability, history, and whitelist
        $this->saveAvailabilityHistoryAndWhitelist($crew);
    }

    /**
     * Update existing crew
     */
    private function update(Crew $crew): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE crews SET
                display_name = :display_name,
                first_name = :first_name,
                last_name = :last_name,
                partner_key = :partner_key,
                mobile = :mobile,
                social_preference = :social_preference,
                membership_number = :membership_number,
                skill = :skill,
                experience = :experience,
                commitment_rank = :commitment_rank,
                rank_membership = :rank_membership,
                rank_absence = :rank_absence,
                user_id = :user_id
            WHERE id = :id
        ');

        $rank = $crew->getRank();
        $stmt->execute([
            'id' => $crew->getId(),
            'display_name' => $crew->getDisplayName(),
            'first_name' => $crew->getFirstName(),
            'last_name' => $crew->getLastName(),
            'partner_key' => $crew->getPartnerKey()?->toString(),
            'mobile' => $crew->getMobile(),
            'social_preference' => $crew->hasSocialPreference() ? 'Yes' : 'No',
            'membership_number' => $crew->getMembershipNumber(),
            'skill' => $crew->getSkill()->value,
            'experience' => $crew->getExperience(),
            'commitment_rank' => $rank->getDimension(CrewRankDimension::COMMITMENT),
            'rank_membership' => $rank->getDimension(CrewRankDimension::MEMBERSHIP),
            'rank_absence' => $rank->getDimension(CrewRankDimension::ABSENCE),
            'user_id' => $crew->getUserId(),
        ]);

        // Update availability, history, and whitelist
        $this->saveAvailabilityHistoryAndWhitelist($crew);
    }

    /**
     * Save crew availability, history, and whitelist
     *
     * Optimized to avoid redundant findByKey() lookups and minimize database roundtrips
     */
    private function saveAvailabilityHistoryAndWhitelist(Crew $crew): void
    {
        $crewId = $crew->getId();

        //  Only save if there's data to save (skip empty arrays)
        $availability = $crew->getAllAvailability();
        if (!empty($availability)) {
            $stmt = $this->pdo->prepare('
                INSERT INTO crew_availability (crew_id, event_id, status)
                VALUES (:crew_id, :event_id, :status)
                ON CONFLICT(crew_id, event_id) DO UPDATE SET status = :status
            ');
            foreach ($availability as $eventIdString => $status) {
                $stmt->execute([
                    'crew_id' => $crewId,
                    'event_id' => $eventIdString,
                    'status' => $status->value,
                ]);
            }
        }

        // Save history (direct insert without lookup)
        $history = $crew->getAllHistory();
        if (!empty($history)) {
            $stmt = $this->pdo->prepare('
                INSERT INTO crew_history (crew_id, event_id, boat_key)
                VALUES (:crew_id, :event_id, :boat_key)
                ON CONFLICT(crew_id, event_id) DO UPDATE SET boat_key = :boat_key
            ');
            foreach ($history as $eventIdString => $boatKey) {
                $stmt->execute([
                    'crew_id' => $crewId,
                    'event_id' => $eventIdString,
                    'boat_key' => $boatKey,
                ]);
            }
        }

        // Save whitelist (delete all and re-insert without lookup)
        $whitelist = $crew->getWhitelist();
        if (!empty($whitelist)) {
            // Delete existing whitelist entries
            $stmt = $this->pdo->prepare('DELETE FROM crew_whitelist WHERE crew_id = :crew_id');
            $stmt->execute(['crew_id' => $crewId]);

            // Insert new whitelist entries
            $stmt = $this->pdo->prepare('
                INSERT OR IGNORE INTO crew_whitelist (crew_id, boat_key)
                VALUES (:crew_id, :boat_key)
            ');
            foreach ($whitelist as $boatKeyString) {
                $stmt->execute([
                    'crew_id' => $crewId,
                    'boat_key' => $boatKeyString,
                ]);
            }
        }
    }

    /**
     * Hydrate crew entity from database row
     */
    private function hydrate(array $row): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($row['key']),
            displayName: $row['display_name'] ?? null,
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            partnerKey: $row['partner_key'] ? CrewKey::fromString($row['partner_key']) : null,
            mobile: !empty($row['mobile']) ? $row['mobile'] : null,
            socialPreference: $row['social_preference'] === 'Yes',
            membershipNumber: !empty($row['membership_number']) ? $row['membership_number'] : null,
            skill: SkillLevel::fromInt((int)$row['skill']),
            experience: !empty($row['experience']) ? $row['experience'] : null,
        );

        $crew->setId((int)$row['id']);

        // Set user_id if present
        if (isset($row['user_id']) && $row['user_id'] !== null) {
            $crew->setUserId((int)$row['user_id']);
        }

        $rank = Rank::forCrew(
            availability: 0, // Database loads don't set availability (event-specific)
            commitment: (int)$row['commitment_rank'],
            membership: (int)$row['rank_membership'],
            absence: (int)$row['rank_absence']
        );
        $crew->setRank($rank);

        // Load availability
        $this->loadAvailability($crew);

        // Load history
        $this->loadHistory($crew);

        // Load whitelist
        $this->loadWhitelist($crew);

        return $crew;
    }

    /**
     * Load crew availability from database
     */
    private function loadAvailability(Crew $crew): void
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id, status FROM crew_availability WHERE crew_id = :crew_id
        ');
        $stmt->execute(['crew_id' => $crew->getId()]);

        while ($row = $stmt->fetch()) {
            $crew->setAvailability(
                EventId::fromString($row['event_id']),
                AvailabilityStatus::from((int)$row['status'])
            );
        }
    }

    /**
     * Load crew history from database
     */
    private function loadHistory(Crew $crew): void
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id, boat_key FROM crew_history WHERE crew_id = :crew_id
        ');
        $stmt->execute(['crew_id' => $crew->getId()]);

        while ($row = $stmt->fetch()) {
            $crew->setHistory(
                EventId::fromString($row['event_id']),
                $row['boat_key']
            );
        }
    }

    /**
     * Load crew whitelist from database
     */
    private function loadWhitelist(Crew $crew): void
    {
        $stmt = $this->pdo->prepare('
            SELECT boat_key FROM crew_whitelist WHERE crew_id = :crew_id
        ');
        $stmt->execute(['crew_id' => $crew->getId()]);

        $whitelist = [];
        while ($row = $stmt->fetch()) {
            $whitelist[] = $row['boat_key'];
        }

        $crew->setWhitelist($whitelist);
    }

    /**
     * Batch load availability for multiple crews (avoids N+1 queries)
     *
     * @param array<int> $crewIds
     * @return array<int, array<string, AvailabilityStatus>> Crew ID => [event_id => status]
     */
    private function batchLoadAvailability(array $crewIds): array
    {
        if (empty($crewIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT crew_id, event_id, status
            FROM crew_availability
            WHERE crew_id IN ($placeholders)
        ");
        $stmt->execute($crewIds);

        $result = [];
        while ($row = $stmt->fetch()) {
            $crewId = (int)$row['crew_id'];
            if (!isset($result[$crewId])) {
                $result[$crewId] = [];
            }
            $result[$crewId][$row['event_id']] = AvailabilityStatus::from((int)$row['status']);
        }

        return $result;
    }

    /**
     * Batch load history for multiple crews (avoids N+1 queries)
     *
     * @param array<int> $crewIds
     * @return array<int, array<string, string>> Crew ID => [event_id => boat_key]
     */
    private function batchLoadHistory(array $crewIds): array
    {
        if (empty($crewIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT crew_id, event_id, boat_key
            FROM crew_history
            WHERE crew_id IN ($placeholders)
        ");
        $stmt->execute($crewIds);

        $result = [];
        while ($row = $stmt->fetch()) {
            $crewId = (int)$row['crew_id'];
            if (!isset($result[$crewId])) {
                $result[$crewId] = [];
            }
            $result[$crewId][$row['event_id']] = $row['boat_key'];
        }

        return $result;
    }

    /**
     * Batch load whitelist for multiple crews (avoids N+1 queries)
     *
     * @param array<int> $crewIds
     * @return array<int, array<string>> Crew ID => [boat_key1, boat_key2, ...]
     */
    private function batchLoadWhitelist(array $crewIds): array
    {
        if (empty($crewIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT crew_id, boat_key
            FROM crew_whitelist
            WHERE crew_id IN ($placeholders)
        ");
        $stmt->execute($crewIds);

        $result = [];
        while ($row = $stmt->fetch()) {
            $crewId = (int)$row['crew_id'];
            if (!isset($result[$crewId])) {
                $result[$crewId] = [];
            }
            $result[$crewId][] = $row['boat_key'];
        }

        return $result;
    }

    /**
     * Hydrate crew entity with pre-loaded data (optimized for batch loading)
     *
     * @param array<string, mixed> $row Database row
     * @param array<string, AvailabilityStatus> $availability Event ID => status
     * @param array<string, string> $history Event ID => boat_key
     * @param array<string> $whitelist Boat keys
     */
    private function hydrateWithData(array $row, array $availability, array $history, array $whitelist): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($row['key']),
            displayName: $row['display_name'] ?? null,
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            partnerKey: $row['partner_key'] ? CrewKey::fromString($row['partner_key']) : null,
            mobile: !empty($row['mobile']) ? $row['mobile'] : null,
            socialPreference: $row['social_preference'] === 'Yes',
            membershipNumber: !empty($row['membership_number']) ? $row['membership_number'] : null,
            skill: SkillLevel::fromInt((int)$row['skill']),
            experience: !empty($row['experience']) ? $row['experience'] : null,
        );

        $crew->setId((int)$row['id']);

        $rank = Rank::forCrew(
            availability: 0, // Database loads don't set availability (event-specific)
            commitment: (int)$row['commitment_rank'],
            membership: (int)$row['rank_membership'],
            absence: (int)$row['rank_absence']
        );
        $crew->setRank($rank);

        // Set pre-loaded availability
        foreach ($availability as $eventIdString => $status) {
            $crew->setAvailability(EventId::fromString($eventIdString), $status);
        }

        // Set pre-loaded history
        foreach ($history as $eventIdString => $boatKey) {
            $crew->setHistory(EventId::fromString($eventIdString), $boatKey);
        }

        // Set pre-loaded whitelist
        $crew->setWhitelist($whitelist);

        return $crew;
    }
}
