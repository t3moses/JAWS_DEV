<?php

declare(strict_types=1);

namespace App\Application\Port\Repository;

use App\Domain\Entity\Crew;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;

/**
 * Crew Repository Interface
 *
 * Defines the contract for crew data persistence.
 * Implementations handle database operations for crew members.
 */
interface CrewRepositoryInterface
{
    /**
     * Find a crew member by key
     *
     * @param CrewKey $key
     * @return Crew|null Crew entity or null if not found
     */
    public function findByKey(CrewKey $key): ?Crew;

    /**
     * Find a crew member by name
     *
     * @param string $firstName
     * @param string $lastName
     * @return Crew|null
     */
    public function findByName(string $firstName, string $lastName): ?Crew;

    /**
     * Find a crew member by user ID
     *
     * @param int $userId
     * @return Crew|null
     */
    public function findByUserId(int $userId): ?Crew;

    /**
     * Get all crew members
     *
     * @return array<Crew>
     */
    public function findAll(): array;

    /**
     * Get crew members available for a specific event
     *
     * @param EventId $eventId
     * @return array<Crew> Crews with status AVAILABLE or GUARANTEED
     */
    public function findAvailableForEvent(EventId $eventId): array;

    /**
     * Get crew members assigned to a specific event
     *
     * @param EventId $eventId
     * @return array<Crew> Crews with status GUARANTEED
     */
    public function findAssignedToEvent(EventId $eventId): array;

    /**
     * Save a crew member (insert or update)
     *
     * @param Crew $crew
     * @return void
     */
    public function save(Crew $crew): void;

    /**
     * Delete a crew member
     *
     * @param CrewKey $key
     * @return void
     */
    public function delete(CrewKey $key): void;

    /**
     * Check if a crew member exists
     *
     * @param CrewKey $key
     * @return bool
     */
    public function exists(CrewKey $key): bool;

    /**
     * Update crew availability for a specific event
     *
     * @param CrewKey $key
     * @param EventId $eventId
     * @param AvailabilityStatus $status
     * @return void
     */
    public function updateAvailability(CrewKey $key, EventId $eventId, AvailabilityStatus $status): void;

    /**
     * Update crew assignment history for a specific event
     *
     * @param CrewKey $key
     * @param EventId $eventId
     * @param string $boatKey Boat key or empty string
     * @return void
     */
    public function updateHistory(CrewKey $key, EventId $eventId, string $boatKey): void;

    /**
     * Add a boat to crew's whitelist
     *
     * @param CrewKey $crewKey
     * @param BoatKey $boatKey
     * @return void
     */
    public function addToWhitelist(CrewKey $crewKey, BoatKey $boatKey): void;

    /**
     * Remove a boat from crew's whitelist
     *
     * @param CrewKey $crewKey
     * @param BoatKey $boatKey
     * @return void
     */
    public function removeFromWhitelist(CrewKey $crewKey, BoatKey $boatKey): void;

    /**
     * Update crew commitment rank only (without touching other fields)
     *
     * @param Crew $crew Crew with updated commitment rank
     * @return void
     */
    public function updateRankCommitment(Crew $crew): void;

    /**
     * Get crew count
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if a display name already exists in the crews table
     *
     * @param string $displayName
     * @return bool
     */
    public function displayNameExists(string $displayName): bool;
}
