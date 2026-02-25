<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;

/**
 * Boat Entity
 *
 * Represents a boat in the fleet with owner details, capacity, preferences, and ranking data.
 */
class Boat
{
    private ?int $id = null;
    private ?int $userId = null;
    private Rank $rank;

    /** @var array<string, int> Berths offered per event (event_id => berth_count) */
    private array $berths = [];

    /** @var array<string, string> Participation history (event_id => 'Y' or '') */
    private array $history = [];

    /**
     * Occupied berths for selection/assignment
     * Public for direct access by selection algorithm
     */
    public int $occupied_berths = 0;

    public function __construct(
        private BoatKey $key,
        private ?string $displayName,
        private string $ownerFirstName,
        private string $ownerLastName,
        private ?string $ownerMobile,
        private int $minBerths,
        private int $maxBerths,
        private bool $assistanceRequired,
        private bool $socialPreference,
    ) {
        // Initialize default rank
        $this->rank = Rank::forBoat(
            flexibility: 1,  // Default: inflexible (not crew)
            absence: 0       // Default: no absences
        );
    }

    // === Identity ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getOwnerUserId(): ?int
    {
        return $this->userId;
    }

    public function setOwnerUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getKey(): BoatKey
    {
        return $this->key;
    }

    // === Basic Information ===

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    // === Owner Information ===

    public function getOwnerFirstName(): string
    {
        return $this->ownerFirstName;
    }

    public function setOwnerFirstName(string $firstName): void
    {
        $this->ownerFirstName = $firstName;
    }

    public function getOwnerLastName(): string
    {
        return $this->ownerLastName;
    }

    public function setOwnerLastName(string $lastName): void
    {
        $this->ownerLastName = $lastName;
    }

    public function getOwnerKey(): CrewKey
    {
        return CrewKey::fromName($this->ownerFirstName, $this->ownerLastName);
    }

    public function getOwnerMobile(): string
    {
        return $this->ownerMobile;
    }

    public function setOwnerMobile(string $mobile): void
    {
        $this->ownerMobile = $mobile;
    }

    // === Capacity ===

    public function getMinBerths(): int
    {
        return $this->minBerths;
    }

    public function setMinBerths(int $berths): void
    {
        $this->minBerths = $berths;
    }

    public function getMaxBerths(): int
    {
        return $this->maxBerths;
    }

    public function setMaxBerths(int $berths): void
    {
        $this->maxBerths = $berths;
    }

    // === Preferences ===

    public function requiresAssistance(): bool
    {
        return $this->assistanceRequired;
    }

    public function setAssistanceRequired(bool $required): void
    {
        $this->assistanceRequired = $required;
    }

    public function hasSocialPreference(): bool
    {
        return $this->socialPreference;
    }

    public function setSocialPreference(bool $preference): void
    {
        $this->socialPreference = $preference;
    }

    public function isWillingToCrew(): bool
    {
        return $this->rank->getDimension(BoatRankDimension::FLEXIBILITY) === 0;
    }

    // === Ranking ===

    public function getRank(): Rank
    {
        return $this->rank;
    }

    public function setRank(Rank $rank): void
    {
        $this->rank = $rank;
    }

    public function setRankDimension(BoatRankDimension $dimension, int $value): void
    {
        $this->rank = $this->rank->withDimension($dimension, $value);
    }

    public function updateAbsenceRank(array $pastEvents): void
    {
        $absences = 0;
        foreach ($pastEvents as $eventId) {
            if (($this->history[$eventId] ?? '') === '') {
                $absences++;
            }
        }
        $this->setRankDimension(BoatRankDimension::ABSENCE, $absences);
    }

    // === Availability (Berths) ===

    public function getBerths(EventId $eventId): int
    {
        return $this->berths[$eventId->toString()] ?? 0;
    }

    public function setBerths(EventId $eventId, int $berths): void
    {
        $this->berths[$eventId->toString()] = $berths;
    }

    /**
     * @return array<string, int>
     */
    public function getAllBerths(): array
    {
        return $this->berths;
    }

    /**
     * @param array<EventId> $eventIds
     */
    public function setAllBerths(array $eventIds, int $berths): void
    {
        foreach ($eventIds as $eventId) {
            $this->setBerths($eventId, $berths);
        }
    }

    public function isAvailableFor(EventId $eventId): bool
    {
        return $this->getBerths($eventId) > 0;
    }

    // === History ===

    public function getHistory(EventId $eventId): string
    {
        return $this->history[$eventId->toString()] ?? '';
    }

    public function setHistory(EventId $eventId, string $participated): void
    {
        $this->history[$eventId->toString()] = $participated;
    }

    /**
     * @return array<string, string>
     */
    public function getAllHistory(): array
    {
        return $this->history;
    }

    /**
     * @param array<EventId> $eventIds
     */
    public function setAllHistory(array $eventIds, string $participated): void
    {
        foreach ($eventIds as $eventId) {
            $this->setHistory($eventId, $participated);
        }
    }

    public function didParticipate(EventId $eventId): bool
    {
        return $this->getHistory($eventId) === 'Y';
    }

    // === Display ===

    public function getOwnerDisplayName(): string
    {
        return $this->ownerFirstName . strtoupper(substr($this->ownerLastName, 0, 1));
    }

    // === Array Conversion (for legacy compatibility) ===

    /**
     * Convert to array format for serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key->toString(),
            'display_name' => $this->displayName,
            'owner_first_name' => $this->ownerFirstName,
            'owner_last_name' => $this->ownerLastName,
            'owner_user_id' => $this->userId,
            'owner_mobile' => $this->ownerMobile,
            'min_berths' => $this->minBerths,
            'max_berths' => $this->maxBerths,
            'assistance_required' => $this->assistanceRequired,
            'social_preference' => $this->socialPreference,
            'rank' => $this->rank->toArray(),
            'berths' => $this->berths,
            'history' => $this->history,
        ];
    }
}
