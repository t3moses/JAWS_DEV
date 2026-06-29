<?php

declare(strict_types=1);

namespace App\Application\UseCase\Flotilla;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Domain\ValueObject\EventId;

/**
 * Get All Flotillas Use Case
 *
 * Retrieves all future events with simplified flotilla information.
 */
class GetAllFlotillasUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * Returns array of future events with simplified flotilla data (display names only).
     * Events are ordered by date ascending.
     *
     * @return array<array<string, mixed>>
     */
    public function execute(): array
    {
        // Get future event IDs (already ordered by date ascending)
        $futureEventIds = $this->eventRepository->findFutureEvents();
        $flotillas = [];

        foreach ($futureEventIds as $eventId) {
            // Load flotilla if exists
            $flotillaData = $this->seasonRepository->getFlotilla(EventId::fromString($eventId));
            $simplifiedFlotilla = $this->simplifyFlotilla($flotillaData);

            // Build response with event ID + simplified flotilla
            $flotillas[] = [
                'eventId' => $eventId,
                'flotilla' => $simplifiedFlotilla, // Can be null
            ];
        }

        return $flotillas;
    }

    /**
     * Simplify flotilla data to include only keys and display names
     *
     * Extracts crewed boats with their crews, plus waitlisted boats and crews.
     * Each boat and crew only includes key and displayName fields.
     *
     * @param array<string, mixed>|null $flotillaData
     * @return array<string, mixed>|null
     */
    private function simplifyFlotilla(?array $flotillaData): ?array
    {
        if ($flotillaData === null) {
            return null;
        }

        $crewedBoats = $flotillaData['crewed_boats'] ?? [];
        $waitlistBoats = $flotillaData['waitlist_boats'] ?? [];
        $waitlistCrews = $flotillaData['waitlist_crews'] ?? [];

        return [
            'crewedBoats' => array_map(function ($crewedBoat) {
                return [
                    'boat' => [
                        'key' => $crewedBoat['boat']['key'],
                        'displayName' => $crewedBoat['boat']['display_name'],
                        'ownerFirstName' => $crewedBoat['boat']['owner_first_name'],
                    ],
                    'crews' => array_map(function ($crew) {
                        return [
                            'key' => $crew['key'],
                            'displayName' => $crew['display_name'],
                        ];
                    }, $crewedBoat['crews'] ?? []),
                ];
            }, $crewedBoats),
            'waitlistedBoats' => array_map(function ($boat) {
                return [
                    'key' => $boat['key'],
                    'displayName' => $boat['display_name'],
                    'ownerFirstName' => $boat['owner_first_name'],
                ];
            }, $waitlistBoats),
            'waitlistedCrews' => array_map(function ($crew) {
                return [
                    'key' => $crew['key'],
                    'displayName' => $crew['display_name'],
                ];
            }, $waitlistCrews),
        ];
    }
}
