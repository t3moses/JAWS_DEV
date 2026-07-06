<?php

declare(strict_types=1);

namespace App\Application\UseCase\Crew;

use App\Application\DTO\Request\UpdateAvailabilityRequest;
use App\Application\DTO\Response\CrewResponse;
use App\Application\Exception\ValidationException;
use App\Application\Exception\BlackoutWindowException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Service\TimeServiceInterface;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\CrewRankDimension;
use Psr\Log\LoggerInterface;

/**
 * Update Crew Availability Use Case
 *
 * Updates crew availability for multiple events.
 */
class UpdateCrewAvailabilityUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
        private TimeServiceInterface $timeService,
        private SeasonRepositoryInterface $seasonRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $userId
     * @param UpdateAvailabilityRequest $request
     * @return CrewResponse
     * @throws ValidationException
     * @throws CrewNotFoundException
     * @throws EventNotFoundException
     */
    public function execute(int $userId, UpdateAvailabilityRequest $request): CrewResponse
    {
        // Check blackout window — only active when an event is scheduled today
        $config = $this->seasonRepository->getConfig();
        if (
            $this->eventRepository->hasEventOnDate($this->timeService->today()) &&
            $this->timeService->isInBlackoutWindow($config['blackout_from'], $config['blackout_to'])
        ) {
            throw new BlackoutWindowException();
        }

        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Find crew by user ID
        $crew = $this->crewRepository->findByUserId($userId);
        if ($crew === null) {
            throw new CrewNotFoundException("Crew not found for user ID: {$userId}");
        }

        $crewKey = $crew->getKey();

        // Update availability for each event using targeted update
        foreach ($request->availabilities as $availability) {
            $eventId = EventId::fromString($availability['eventId']);

            // Validate event exists
            if (!$this->eventRepository->exists($eventId)) {
                throw new EventNotFoundException($eventId);
            }

            // Map boolean: true = register as available (NOT_SELECTED), false = withdraw (delete record)
            if ($availability['isAvailable']) {
                $this->crewRepository->updateAvailability($crewKey, $eventId, AvailabilityStatus::NOT_SELECTED);
            } else {
                $this->crewRepository->deleteAvailability($crewKey, $eventId);
            }
        }

        // Update commitment rank immediately if the next event was included in the request.
        // Re-registering as available (rank=2) clears any admin penalty (rank=1).
        $nextEventCommitmentRank = null;
        $nextEventIdStr = $this->eventRepository->findNextEvent();
        if ($nextEventIdStr !== null) {
            foreach ($request->availabilities as $availability) {
                if ($availability['eventId'] === $nextEventIdStr) {
                    $commitmentRank = $availability['isAvailable'] ? 2 : 0;
                    $crew->setRankDimension(CrewRankDimension::COMMITMENT, $commitmentRank);
                    $this->crewRepository->updateRankCommitment($crew);
                    $nextEventCommitmentRank = $commitmentRank;
                    break;
                }
            }
        }

        // Reload crew to get updated availability for response
        $crew = $this->crewRepository->findByUserId($userId);

        $logContext = [
            'crew_key'    => $crewKey->toString(),
            'event_count' => count($request->availabilities),
        ];
        if ($nextEventCommitmentRank !== null) {
            $logContext['next_event_commitment_rank'] = $nextEventCommitmentRank;
        }
        $this->logger->info('crew.availability_updated', $logContext);

        return CrewResponse::fromEntity($crew);
    }
}
