<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\BoatRankDimension;
use App\Domain\Enum\CrewRankDimension;
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
     * @param EventId|null $nextEventId Next event for availability calculation (optional)
     * @return Rank Calculated crew rank (4D: availability, commitment, membership, absence)
     */
    public function calculateCrewRank(
        Crew $crew,
        array $pastEventIds,
        ?EventId $nextEventId = null
    ): Rank {
        $availabilityDimension = $this->resolveAvailabilityDimension($crew, $nextEventId);

        // Get crew's persistent commitment rank (admin-set, 0-2)
        // Uses the crew_rank_commitment from database, not auto-calculated
        $commitment = $crew->getRank()->getDimension(CrewRankDimension::COMMITMENT);

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

        return Rank::forCrew($availabilityDimension, $commitment, $membership, $absence);
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
     * Update availability rank dimension for crews based on their selection status
     * for the next event (crew_availability.status = 1 → SELECTED)
     *
     * Availability is the primary (most significant) crew rank dimension, so this
     * must be refreshed every pipeline run — it is not persisted independently and
     * otherwise only gets set once, at registration time.
     *
     * @param array<Crew> $crews
     * @param EventId $nextEventId
     */
    public function updateCrewAvailabilityRanks(array $crews, EventId $nextEventId): void
    {
        foreach ($crews as $crew) {
            $crew->setRankDimension(
                CrewRankDimension::AVAILABILITY,
                $this->resolveAvailabilityDimension($crew, $nextEventId)
            );
        }
    }

    /**
     * Resolve the availability rank dimension (0-1) for a crew against the next event
     *
     * 1 = crew has a crew_availability record with status=1 (SELECTED) for the next event
     * 0 = not available, or no next event to compare against
     */
    private function resolveAvailabilityDimension(Crew $crew, ?EventId $nextEventId): int
    {
        if ($nextEventId === null) {
            return 0;
        }

        return $crew->getAvailability($nextEventId)->value === 1 ? 1 : 0;
    }

    /**
     * Update commitment rank for crews based on assignment for the next event
     *
     * Commitment rank is now persistent and admin-set (0-2), but we temporarily boost
     * assigned crews to rank 3 for sorting purposes in this cycle only.
     *
     * @param array<Crew> $crews
     * @param EventId $nextEventId
     * @param array<string> $assignedCrewKeys Crew keys assigned to the next event
     */
    public function updateCrewCommitmentRanks(array $crews, EventId $nextEventId, array $assignedCrewKeys = []): void
    {
        foreach ($crews as $crew) {
            $storedRank = $crew->getRank()->getDimension(CrewRankDimension::COMMITMENT);

            // Crew assigned to next event gets highest priority (overrides penalty)
            if (in_array($crew->getKey()->toString(), $assignedCrewKeys, true)) {
                $crew->setRankDimension(CrewRankDimension::COMMITMENT, 3);
                continue;
            }

            // Crew not assigned: keep their persistent stored rank (0, 1, or 2)
            // No need to recalculate; admin sets this value
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
