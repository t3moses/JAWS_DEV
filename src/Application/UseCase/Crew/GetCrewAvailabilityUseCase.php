<?php

declare(strict_types=1);

namespace App\Application\UseCase\Crew;

use App\Application\DTO\Response\AvailabilityResponse;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Domain\Enum\AvailabilityStatus;

/**
 * Get Crew Availability Use Case
 *
 * Retrieves crew availability across all events in a simplified boolean format.
 */
class GetCrewAvailabilityUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $userId
     * @return AvailabilityResponse
     * @throws CrewNotFoundException
     */
    public function execute(int $userId): AvailabilityResponse
    {
        // Find crew by user ID
        $crew = $this->crewRepository->findByUserId($userId);
        if ($crew === null) {
            throw new CrewNotFoundException("Crew not found for user ID: {$userId}");
        }

        // Get event_id => event_date mapping (single efficient query)
        $eventDateMap = $this->eventRepository->getEventDateMap();

        // Convert to boolean format with ISO dates
        // For each event, return true if crew has any availability record (is registered)
        // Return false if crew has no record (not registered)
        $availability = [];
        foreach ($eventDateMap as $eventId => $eventDate) {
            // Check if crew has an availability record for this event
            // getAvailability returns NOT_SELECTED (default) if no record exists
            $crewAvail = $crew->getAllAvailability();
            $hasRecord = isset($crewAvail[$eventId]);
            $availability[$eventDate] = $hasRecord;
        }

        return new AvailabilityResponse($availability);
    }
}
