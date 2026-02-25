<?php

declare(strict_types=1);

namespace App\Application\Port\Repository;

use App\Domain\Entity\Boat;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;

/**
 * Boat Repository Interface
 *
 * Defines the contract for boat data persistence.
 * Implementations handle database operations for boats.
 */
interface BoatRepositoryInterface
{
    /**
     * Find a boat by its key
     *
     * @param BoatKey $key
     * @return Boat|null Boat entity or null if not found
     */
    public function findByKey(BoatKey $key): ?Boat;

    /**
     * Find a boat by owner name
     *
     * @param string $firstName
     * @param string $lastName
     * @return Boat|null
     */
    public function findByOwnerName(string $firstName, string $lastName): ?Boat;

    /**
     * Find a boat by owner user ID
     *
     * @param int $userId
     * @return Boat|null
     */
    public function findByOwnerUserId(int $userId): ?Boat;

    /**
     * Get all boats
     *
     * @return array<Boat>
     */
    public function findAll(): array;

    /**
     * Get boats available for a specific event
     *
     * @param EventId $eventId
     * @return array<Boat> Boats with berths > 0 for this event
     */
    public function findAvailableForEvent(EventId $eventId): array;

    /**
     * Save a boat (insert or update)
     *
     * @param Boat $boat
     * @return void
     */
    public function save(Boat $boat): void;

    /**
     * Delete a boat
     *
     * @param BoatKey $key
     * @return void
     */
    public function delete(BoatKey $key): void;

    /**
     * Check if a boat exists
     *
     * @param BoatKey $key
     * @return bool
     */
    public function exists(BoatKey $key): bool;

    /**
     * Update boat availability (berths) for a specific event
     *
     * @param BoatKey $key
     * @param EventId $eventId
     * @param int $berths
     * @return void
     */
    public function updateAvailability(BoatKey $key, EventId $eventId, int $berths): void;

    /**
     * Update boat participation history for a specific event
     *
     * @param BoatKey $key
     * @param EventId $eventId
     * @param string $participated 'Y' or ''
     * @return void
     */
    public function updateHistory(BoatKey $key, EventId $eventId, string $participated): void;

    /**
     * Update boat flexibility rank only (without touching other fields)
     *
     * @param Boat $boat Boat with updated flexibility rank
     * @return void
     */
    public function updateRankFlexibility(Boat $boat): void;

    /**
     * Get boat count
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if a display name already exists in the boats table
     *
     * @param string $displayName
     * @return bool
     */
    public function displayNameExists(string $displayName): bool;
}
