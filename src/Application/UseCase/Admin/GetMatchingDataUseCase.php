<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\EventNotFoundException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Domain\ValueObject\EventId;

/**
 * Get Matching Data Use Case
 *
 * Retrieves matching data for an event (available boats, available crews, capacity analysis).
 * This is used by administrators to understand the matching situation before assignments are made.
 */
class GetMatchingDataUseCase
{
    public function __construct(
        private BoatRepositoryInterface $boatRepository,
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param string $eventId
     * @return array{available_boats: array, available_crews: array, capacity: array}
     * @throws EventNotFoundException
     */
    public function execute(string $eventId): array
    {
        $eventId = EventId::fromString($eventId);

        // Verify event exists
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            throw new EventNotFoundException($eventId);
        }

        // Get available boats and crews
        $availableBoats = $this->boatRepository->findAvailableForEvent($eventId);
        $availableCrews = $this->crewRepository->findAvailableForEvent($eventId);

        // Calculate capacity
        $totalBerths = 0;
        $minBerths = 0;
        $maxBerths = 0;

        foreach ($availableBoats as $boat) {
            $berths = $boat->getBerths($eventId);
            $totalBerths += $berths;
            $minBerths += $boat->getMinBerths();
            $maxBerths += $boat->getMaxBerths();
        }

        $totalCrews = count($availableCrews);

        // Determine capacity scenario
        $scenario = $this->determineScenario($totalBerths, $totalCrews);

        return [
            'available_boats' => array_map(
                fn($boat) => [
                    'key' => $boat->getKey()->toString(),
                    'display_name' => $boat->getDisplayName(),
                    'berths' => $boat->getBerths($eventId),
                    'min_berths' => $boat->getMinBerths(),
                    'max_berths' => $boat->getMaxBerths(),
                    'requires_assistance' => $boat->requiresAssistance(),
                ],
                $availableBoats
            ),
            'available_crews' => array_map(
                fn($crew) => [
                    'key' => $crew->getKey()->toString(),
                    'display_name' => $crew->getDisplayName(),
                    'skill' => $crew->getSkill()->value,
                    'availability' => $crew->getAvailability($eventId)->value,
                ],
                $availableCrews
            ),
            'capacity' => [
                'total_berths' => $totalBerths,
                'total_crews' => $totalCrews,
                'min_berths' => $minBerths,
                'max_berths' => $maxBerths,
                'scenario' => $scenario,
                'surplus_deficit' => $totalBerths - $totalCrews,
            ],
        ];
    }

    /**
     * Determine capacity scenario (too few crews, too many crews, perfect fit)
     */
    private function determineScenario(int $totalBerths, int $totalCrews): string
    {
        if ($totalCrews < $totalBerths) {
            return 'too_few_crews';
        } elseif ($totalCrews > $totalBerths) {
            return 'too_many_crews';
        } else {
            return 'perfect_fit';
        }
    }
}
