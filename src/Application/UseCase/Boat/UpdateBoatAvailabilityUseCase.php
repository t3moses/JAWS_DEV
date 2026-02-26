<?php

declare(strict_types=1);

namespace App\Application\UseCase\Boat;

use App\Application\DTO\Request\UpdateAvailabilityRequest;
use App\Application\DTO\Response\BoatResponse;
use App\Application\Exception\ValidationException;
use App\Application\Exception\BlackoutWindowException;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Service\TimeServiceInterface;
use App\Domain\ValueObject\EventId;

/**
 * Update Boat Availability Use Case
 *
 * Updates berths offered by a boat for multiple events.
 */
class UpdateBoatAvailabilityUseCase
{
    public function __construct(
        private BoatRepositoryInterface $boatRepository,
        private EventRepositoryInterface $eventRepository,
        private TimeServiceInterface $timeService,
        private SeasonRepositoryInterface $seasonRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $userId
     * @param UpdateAvailabilityRequest $request
     * @return BoatResponse
     * @throws ValidationException
     * @throws BoatNotFoundException
     * @throws EventNotFoundException
     */
    public function execute(int $userId, UpdateAvailabilityRequest $request): BoatResponse
    {
        // Check blackout window
        $config = $this->seasonRepository->getConfig();
        if ($this->timeService->isInBlackoutWindow($config['blackout_from'], $config['blackout_to'])) {
            throw new BlackoutWindowException();
        }

        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Find boat by owner user ID
        $boat = $this->boatRepository->findByOwnerUserId($userId);
        if ($boat === null) {
            throw new BoatNotFoundException("Boat not found for user ID: {$userId}");
        }

        // Get boat capacity once
        $maxBerths = $boat->getMaxBerths();
        $boatKey = $boat->getKey();

        // Update availability for each event using targeted update
        foreach ($request->availabilities as $availability) {
            $eventId = EventId::fromString($availability['eventId']);

            // Validate event exists
            if (!$this->eventRepository->exists($eventId)) {
                throw new EventNotFoundException($eventId);
            }

            // Use explicit berths if provided (from dropdown), else fall back to boolean
            if (array_key_exists('berths', $availability)) {
                $berths = (int) $availability['berths'];
                if ($berths > $maxBerths) {
                    throw new ValidationException(["boat" => "Berths cannot exceed maximum capacity ({$maxBerths})"]);
                }
            } else {
                if ($availability['isAvailable'] && $maxBerths <= 0) {
                    throw new ValidationException(['boat' => 'Boat has no capacity configured']);
                }
                $berths = $availability['isAvailable'] ? $maxBerths : 0;
            }

            // Use targeted update instead of loading, modifying, and saving entire entity
            $this->boatRepository->updateAvailability($boatKey, $eventId, $berths);
        }

        // Reload boat to get updated availability for response
        $boat = $this->boatRepository->findByOwnerUserId($userId);

        return BoatResponse::fromEntity($boat);
    }
}
