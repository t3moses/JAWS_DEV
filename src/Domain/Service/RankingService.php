<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;
use App\Domain\Enum\CrewRankDimension;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Collection\Squad;

/**
 * Ranking Service
 *
 * Handles rank calculations and updates for boats and crews.
 * Ranks are used by the Selection algorithm to prioritize entities.
 */
class RankingService
{
    /**
     * Calculate initial rank for a crew
     *
     * @param Crew $crew Crew entity
     * @param array<string> $pastEventIds Past event IDs for absence calculation
     * @param EventId|null $nextEventId Next event for commitment calculation (optional)
     * @return Rank Calculated crew rank (3D: commitment, membership, absence)
     */
    public function calculateCrewRank(
        Crew $crew,
        array $pastEventIds,
        ?EventId $nextEventId = null
    ): Rank {
        // Calculate commitment (availability for next event)
        // Higher value = higher priority (SelectionService sorts descending)
        $commitment = 2; // Default: AVAILABLE = normal priority
        if ($nextEventId !== null) {
            $availability = $crew->getAvailability($nextEventId);
            $commitment = match ($availability) {
                AvailabilityStatus::GUARANTEED => 3,    // High priority (assigned to next event)
                AvailabilityStatus::AVAILABLE => 2,     // Normal priority
                AvailabilityStatus::WITHDRAWN => 1,     // Admin no-show penalty
                AvailabilityStatus::UNAVAILABLE => 0,   // No priority
            };
        }

        // Calculate membership (has valid membership number)
        $membership = Crew::calculateMembershipRank($crew->getMembershipNumber());

        // Calculate absence (count of past no-shows)
        $absence = 0;
        foreach ($pastEventIds as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);
            if ($crew->getHistory($eventId) === '') {
                $absence++;
            }
        }

        return Rank::forCrew($commitment, $membership, $absence);
    }

    /**
     * Calculate initial rank for a boat
     *
     * @param Boat $boat Boat entity
     * @param array<string> $pastEventIds Past event IDs for absence calculation
     * @param Squad|null $squad Squad for flexibility calculation (optional)
     * @return Rank Calculated boat rank (2D: flexibility, absence)
     */
    public function calculateBoatRank(
        Boat $boat,
        array $pastEventIds,
        ?Squad $squad = null
    ): Rank {
        // Calculate flexibility (whether owner is also crew)
        $flexibility = 1; // Default: not flexible
        if ($squad !== null) {
            $ownerKey = $boat->getOwnerKey();
            foreach ($squad->all() as $crew) {
                if ($crew->getKey()->equals($ownerKey)) {
                    $flexibility = 0; // Flexible (owner is crew)
                    break;
                }
            }
        }

        // Calculate absence (count of past no-shows)
        $absence = 0;
        foreach ($pastEventIds as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);
            if ($boat->getHistory($eventId) === '') {
                $absence++;
            }
        }

        return Rank::forBoat($flexibility, $absence);
    }

    /**
     * Update absence rank for boats based on past events
     *
     * @param array<Boat> $boats
     * @param array<string> $pastEventIds Array of past event ID strings
     */
    public function updateBoatAbsenceRanks(array $boats, array $pastEventIds): void
    {
        foreach ($boats as $boat) {
            $absences = 0;
            foreach ($pastEventIds as $eventIdString) {
                $eventId = EventId::fromString($eventIdString);
                if ($boat->getHistory($eventId) === '') {
                    $absences++;
                }
            }
            $boat->setRankDimension(BoatRankDimension::ABSENCE, $absences);
        }
    }

    /**
     * Update absence rank for crews based on past events
     *
     * @param array<Crew> $crews
     * @param array<string> $pastEventIds Array of past event ID strings
     */
    public function updateCrewAbsenceRanks(array $crews, array $pastEventIds): void
    {
        foreach ($crews as $crew) {
            $absences = 0;
            foreach ($pastEventIds as $eventIdString) {
                $eventId = EventId::fromString($eventIdString);
                if ($crew->getHistory($eventId) === '') {
                    $absences++;
                }
            }
            $crew->setRankDimension(CrewRankDimension::ABSENCE, $absences);
        }
    }

    /**
     * Update commitment rank for crews based on availability and assignment for the next event
     *
     * 4-level commitment rank (higher value = higher priority):
     *   3 = assigned to next event (set after pipeline assigns berths)
     *   2 = normal priority (crew registered available)
     *   1 = admin penalty (preserved; not overwritten by this method unless crew re-registers)
     *   0 = unavailable/withdrawn
     *
     * @param array<Crew> $crews
     * @param EventId $nextEventId
     * @param array<string> $assignedCrewKeys Crew keys assigned to the next event
     */
    public function updateCrewCommitmentRanks(array $crews, EventId $nextEventId, array $assignedCrewKeys = []): void
    {
        foreach ($crews as $crew) {
            // Admin penalty (rank=1) persists — do not overwrite unless crew re-registers
            $storedRank = $crew->getRank()->getDimension(CrewRankDimension::COMMITMENT);
            if ($storedRank === 1) {
                continue;
            }

            // Crew assigned to next event gets highest priority
            if (in_array($crew->getKey()->toString(), $assignedCrewKeys, true)) {
                $crew->setRankDimension(CrewRankDimension::COMMITMENT, 3);
                continue;
            }

            // Map availability to commitment rank
            // Higher value = higher priority (SelectionService sorts descending)
            $availability = $crew->getAvailability($nextEventId);
            $commitmentRank = match ($availability) {
                AvailabilityStatus::GUARANTEED => 3,    // Currently assigned for this event
                AvailabilityStatus::AVAILABLE => 2,     // Normal priority
                AvailabilityStatus::WITHDRAWN => 1,     // Admin no-show penalty
                default => 0,                           // UNAVAILABLE
            };

            $crew->setRankDimension(CrewRankDimension::COMMITMENT, $commitmentRank);
        }
    }

    /**
     * Update membership rank for a crew
     *
     * @param Crew $crew
     */
    public function updateCrewMembershipRank(Crew $crew): void
    {
        $membershipRank = Crew::calculateMembershipRank($crew->getMembershipNumber());
        $crew->setRankDimension(CrewRankDimension::MEMBERSHIP, $membershipRank);
    }

    /**
     * Update all ranks for boats
     *
     * @param array<Boat> $boats
     * @param array<string> $pastEventIds
     */
    public function updateAllBoatRanks(array $boats, array $pastEventIds): void
    {
        $this->updateBoatAbsenceRanks($boats, $pastEventIds);
        // Flexibility rank is updated by FlexService
    }

    /**
     * Update all ranks for crews
     *
     * @param array<Crew> $crews
     * @param array<string> $pastEventIds
     * @param EventId $nextEventId
     */
    public function updateAllCrewRanks(array $crews, array $pastEventIds, EventId $nextEventId): void
    {
        $this->updateCrewAbsenceRanks($crews, $pastEventIds);
        $this->updateCrewCommitmentRanks($crews, $nextEventId);
        // Flexibility and membership ranks are updated elsewhere
    }
}
