<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\CrewRankDimension;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;

/**
 * Crew Entity
 *
 * Represents a crew member with skills, preferences, availability, and ranking data.
 */
class Crew
{
    private ?int $id = null;
    private ?int $userId = null;
    private Rank $rank;

    /** @var array<string, AvailabilityStatus> Availability per event (event_id => status) */
    private array $availability = [];

    /** @var array<string, string> Assignment history (event_id => boat_key or '') */
    private array $history = [];

    /** @var array<string> List of whitelisted boat keys */
    private array $whitelist = [];

    public function __construct(
        private CrewKey $key,
        private ?string $displayName,
        private string $firstName,
        private string $lastName,
        private ?CrewKey $partnerKey,
        private ?string $mobile,
        private bool $socialPreference,
        private ?string $membershipNumber,
        private SkillLevel $skill,
        private ?string $experience,
    ) {
        // Initialize default rank
        $this->rank = Rank::forCrew(
            commitment: 0,   // Default: unavailable
            membership: self::calculateMembershipRank($membershipNumber),
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

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getKey(): CrewKey
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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    // === Partner ===

    public function getPartnerKey(): ?CrewKey
    {
        return $this->partnerKey;
    }

    public function setPartnerKey(?CrewKey $partnerKey): void
    {
        $this->partnerKey = $partnerKey;
    }

    public function hasPartner(): bool
    {
        return $this->partnerKey !== null;
    }

    // === Contact ===

    public function getMobile(): ?string
    {
        return $this->mobile;
    }

    public function setMobile(?string $mobile): void
    {
        $this->mobile = $mobile;
    }

    // === Preferences ===

    public function hasSocialPreference(): bool
    {
        return $this->socialPreference;
    }

    public function setSocialPreference(bool $preference): void
    {
        $this->socialPreference = $preference;
    }

    // === Membership ===

    public function getMembershipNumber(): ?string
    {
        return $this->membershipNumber;
    }

    public function setMembershipNumber(?string $membershipNumber): void
    {
        $this->membershipNumber = $membershipNumber;
        // Update rank when membership changes
        $this->setRankDimension(
            CrewRankDimension::MEMBERSHIP,
            self::calculateMembershipRank($membershipNumber)
        );
    }

    public function isMember(): bool
    {
        return !empty($this->membershipNumber) && $this->membershipNumber !== null;
    }

    // === Skills ===

    public function getSkill(): SkillLevel
    {
        return $this->skill;
    }

    public function setSkill(SkillLevel $skill): void
    {
        $this->skill = $skill;
    }

    public function getExperience(): ?string
    {
        return $this->experience;
    }

    public function setExperience(?string $experience): void
    {
        $this->experience = $experience;
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

    public function setRankDimension(CrewRankDimension $dimension, int $value): void
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
        $this->setRankDimension(CrewRankDimension::ABSENCE, $absences);
    }

    /**
     * Calculate membership rank based on validation rules
     *
     * Algorithm:
     * 1. Remove all non-alphanumeric characters
     * 2. If remaining string contains non-numeric characters → rank 0 (invalid)
     * 3. If length < 4 or > 9 → rank 0 (invalid)
     * 4. Otherwise → rank 1 (valid)
     *
     * @param string|null $membershipNumber
     * @return int 0 for invalid (higher priority), 1 for valid (lower priority)
     */
    public static function calculateMembershipRank(?string $membershipNumber): int
    {
        // Handle null/empty - invalid
        if ($membershipNumber === null || $membershipNumber === '') {
            return 0;
        }

        // Step 1: Remove all non-alphanumeric characters
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $membershipNumber);

        // Check if empty after cleaning
        if ($cleaned === '') {
            return 0; // Invalid: nothing left
        }

        // Step 2: If remaining string contains non-numeric characters → rank 0
        if (preg_match('/[^0-9]/', $cleaned)) {
            return 0; // Invalid: has letters
        }

        // Step 3: If length < 4 or > 9 → rank 0
        $length = strlen($cleaned);
        if ($length < 4 || $length > 9) {
            return 0; // Invalid: wrong length
        }

        // Step 4: Otherwise → rank 1 (valid membership)
        return 1;
    }

    // === Availability ===

    public function getAvailability(EventId $eventId): AvailabilityStatus
    {
        return $this->availability[$eventId->toString()] ?? AvailabilityStatus::UNAVAILABLE;
    }

    public function setAvailability(EventId $eventId, AvailabilityStatus $status): void
    {
        $this->availability[$eventId->toString()] = $status;
    }

    /**
     * @return array<string, AvailabilityStatus>
     */
    public function getAllAvailability(): array
    {
        return $this->availability;
    }

    /**
     * @param array<EventId> $eventIds
     */
    public function setAllAvailability(array $eventIds, AvailabilityStatus $status): void
    {
        foreach ($eventIds as $eventId) {
            $this->setAvailability($eventId, $status);
        }
    }

    public function isAvailableFor(EventId $eventId): bool
    {
        return $this->getAvailability($eventId)->canParticipate();
    }

    public function isAssignedTo(EventId $eventId): bool
    {
        return $this->getAvailability($eventId)->isAssigned();
    }

    // === History ===

    public function getHistory(EventId $eventId): string
    {
        return $this->history[$eventId->toString()] ?? '';
    }

    public function setHistory(EventId $eventId, string $boatKey): void
    {
        $this->history[$eventId->toString()] = $boatKey;
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
    public function setAllHistory(array $eventIds, string $boatKey): void
    {
        foreach ($eventIds as $eventId) {
            $this->setHistory($eventId, $boatKey);
        }
    }

    public function wasAssignedTo(EventId $eventId): ?BoatKey
    {
        $boatKey = $this->getHistory($eventId);
        return empty($boatKey) ? null : BoatKey::fromString($boatKey);
    }

    // === Whitelist ===

    /**
     * @return array<string>
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }

    public function addToWhitelist(BoatKey $boatKey): void
    {
        $key = $boatKey->toString();
        if (!in_array($key, $this->whitelist, true)) {
            $this->whitelist[] = $key;
        }
    }

    public function removeFromWhitelist(BoatKey $boatKey): void
    {
        $this->whitelist = array_values(
            array_filter(
                $this->whitelist,
                fn($k) => $k !== $boatKey->toString()
            )
        );
    }

    public function isWhitelisted(BoatKey $boatKey): bool
    {
        return in_array($boatKey->toString(), $this->whitelist, true);
    }

    /**
     * @param array<string> $whitelist
     */
    public function setWhitelist(array $whitelist): void
    {
        $this->whitelist = $whitelist;
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
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'partner_key' => $this->partnerKey?->toString(),
            'user_id' => $this->userId,
            'mobile' => $this->mobile,
            'social_preference' => $this->socialPreference,
            'membership_number' => $this->membershipNumber,
            'skill' => $this->skill->value,
            'experience' => $this->experience,
            'rank' => $this->rank->toArray(),
            'availability' => array_map(fn($s) => $s->value, $this->availability),
            'history' => $this->history,
            'whitelist' => $this->whitelist,
        ];
    }
}
