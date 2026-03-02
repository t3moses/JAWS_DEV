<?php

declare(strict_types=1);

namespace App\Application\UseCase\Event;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Service\TimeServiceInterface;

/**
 * Get Status Use Case
 *
 * Returns current server status including whether registration is in a blackout window.
 */
class GetStatusUseCase
{
    public function __construct(
        private SeasonRepositoryInterface $seasonRepository,
        private EventRepositoryInterface $eventRepository,
        private TimeServiceInterface $timeService,
    ) {
    }

    /**
     * @return array{isBlackout: bool, currentDate: string, currentTime: string, timeSource: string}
     */
    public function execute(): array
    {
        $config = $this->seasonRepository->getConfig();
        $now = $this->timeService->now();
        $hasEventToday = $this->eventRepository->hasEventOnDate($this->timeService->today());
        $isBlackout = $hasEventToday && $this->timeService->isInBlackoutWindow(
            $config['blackout_from'],
            $config['blackout_to']
        );

        return [
            'isBlackout'  => $isBlackout,
            'currentDate' => $now->format('Y-m-d'),
            'currentTime' => $now->format('H:i:s'),
            'timeSource'  => $config['source'],
        ];
    }
}
