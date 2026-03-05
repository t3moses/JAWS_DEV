<?php

declare(strict_types=1);

namespace App\Application\UseCase\Event;

use App\Application\DTO\Response\EventResponse;
use App\Application\DTO\Response\FlotillaResponse;
use App\Application\Exception\EventNotFoundException;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Domain\ValueObject\EventId;

/**
 * Get Event Use Case
 *
 * Retrieves event details with flotilla assignments.
 */
class GetEventUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param string $eventId
     * @return array{event: EventResponse, flotilla: FlotillaResponse|null}
     * @throws EventNotFoundException
     */
    public function execute(string $eventId): array
    {
        $eventId = EventId::fromString($eventId);

        // Get event data
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            throw new EventNotFoundException($eventId);
        }

        $eventResponse = EventResponse::fromArray($eventData);

        // Get flotilla if exists
        $flotillaData = $this->seasonRepository->getFlotilla($eventId);
        $flotillaResponse = $flotillaData !== null
            ? FlotillaResponse::fromFlotilla($eventId->toString(), $flotillaData)
            : null;

        return [
            'event' => $eventResponse,
            'flotilla' => $flotillaResponse,
        ];
    }
}
