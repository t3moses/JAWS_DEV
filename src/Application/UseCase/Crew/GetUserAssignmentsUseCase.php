<?php

declare(strict_types=1);

namespace App\Application\UseCase\Crew;

use App\Application\DTO\Response\AssignmentResponse;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;

/**
 * Get User Assignments Use Case
 *
 * Retrieves all assignments for a user across all events.
 * - For crew members: Shows which boats they're assigned to and their crewmates
 * - For boat owners: Shows which crew members are assigned to their boat
 * - For flex users (both): Shows crew assignments if they're assigned as crew, otherwise boat assignments
 */
class GetUserAssignmentsUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $userId
     * @return array<AssignmentResponse>
     */
    public function execute(int $userId): array
    {
        // Check if user is a crew member and/or boat owner
        $crew = $this->crewRepository->findByUserId($userId);
        $boat = $this->boatRepository->findByOwnerUserId($userId);

        // If neither crew nor boat owner, return empty array
        if ($crew === null && $boat === null) {
            return [];
        }

        // Get all events
        $allEvents = $this->eventRepository->findAll();
        $assignments = [];

        // For each event, check assignments
        foreach ($allEvents as $eventIdString) {
            $eventId = \App\Domain\ValueObject\EventId::fromString($eventIdString);
            $eventData = $this->eventRepository->findById($eventId);

            if ($eventData === null) {
                continue;
            }

            // Get flotilla for this event
            $flotilla = $this->seasonRepository->getFlotilla($eventId);

            if ($flotilla === null) {
                // No flotilla yet - show availability status only if user is crew
                if ($crew !== null) {
                    $availability = $crew->getAvailability($eventId);
                    $assignments[] = new AssignmentResponse(
                        eventId: $eventIdString,
                        eventDate: $eventData['event_date'],
                        startTime: $eventData['start_time'],
                        finishTime: $eventData['finish_time'],
                        availabilityStatus: $availability->value,
                        boatName: null,
                        boatKey: null,
                        crewmates: [],
                    );
                }
                continue;
            }

            // Check assignments based on user type
            $found = false;

            // If user is a crew member, find their crew assignment
            if ($crew !== null) {
                foreach ($flotilla['crewed_boats'] as $crewedBoat) {
                    $boatData = $crewedBoat['boat'];
                    $crews = $crewedBoat['crews'];

                    foreach ($crews as $assignedCrew) {
                        // Compare crew keys
                        if ($assignedCrew['key'] === $crew->getKey()->toString()) {
                            // Found assignment - extract crewmate data
                            $crewmates = array_map(
                                fn($c) => [
                                    'key' => $c['key'],
                                    'display_name' => $c['display_name'],
                                    'skill' => $c['skill'],
                                ],
                                array_filter($crews, fn($c) => $c['key'] !== $crew->getKey()->toString())
                            );

                            $assignments[] = new AssignmentResponse(
                                eventId: $eventIdString,
                                eventDate: $eventData['event_date'],
                                startTime: $eventData['start_time'],
                                finishTime: $eventData['finish_time'],
                                availabilityStatus: $crew->getAvailability($eventId)->value,
                                boatName: $boatData['display_name'],
                                boatKey: $boatData['key'],
                                crewmates: array_values($crewmates),
                            );
                            $found = true;
                            break 2;
                        }
                    }
                }
            }

            // If user is a boat owner, find their boat assignment
            if (!$found && $boat !== null) {
                foreach ($flotilla['crewed_boats'] as $crewedBoat) {
                    $boatData = $crewedBoat['boat'];

                    // Check if this is the user's boat
                    if ($boatData['key'] === $boat->getKey()->toString()) {
                        // Found boat assignment - extract crew data
                        $crews = $crewedBoat['crews'];
                        $crewmates = array_map(
                            fn($c) => [
                                'key' => $c['key'],
                                'display_name' => $c['display_name'],
                                'skill' => $c['skill'],
                            ],
                            $crews
                        );

                        // For boat owners with crew assigned, status is SELECTED (1)
                        // If the boat is in crewed_boats, it's confirmed for the event
                        $availabilityStatus = 1; // SELECTED

                        $assignments[] = new AssignmentResponse(
                            eventId: $eventIdString,
                            eventDate: $eventData['event_date'],
                            startTime: $eventData['start_time'],
                            finishTime: $eventData['finish_time'],
                            availabilityStatus: $availabilityStatus,
                            boatName: $boatData['display_name'],
                            boatKey: $boatData['key'],
                            crewmates: array_values($crewmates),
                        );
                        $found = true;
                        break;
                    }
                }
            }

            // If crew member not assigned to any boat, show availability status
            if (!$found && $crew !== null) {
                $availability = $crew->getAvailability($eventId);
                $assignments[] = new AssignmentResponse(
                    eventId: $eventIdString,
                    eventDate: $eventData['event_date'],
                    startTime: $eventData['start_time'],
                    finishTime: $eventData['finish_time'],
                    availabilityStatus: $availability->value,
                    boatName: null,
                    boatKey: null,
                    crewmates: [],
                );
            }
        }

        return $assignments;
    }
}
