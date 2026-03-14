<?php

declare(strict_types=1);

namespace App\Application\UseCase\Season;

use App\Application\DTO\Request\UpdateConfigRequest;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Service\TimeServiceInterface;
use App\Domain\Enum\TimeSource;
use Psr\Log\LoggerInterface;

/**
 * Update Config Use Case
 *
 * Updates season configuration (time source, year, event times, blackout window).
 * This replaces the admin_update.php functionality.
 */
class UpdateConfigUseCase
{
    public function __construct(
        private SeasonRepositoryInterface $seasonRepository,
        private TimeServiceInterface $timeService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param UpdateConfigRequest $request
     * @return array{success: bool, message: string}
     * @throws ValidationException
     */
    public function execute(UpdateConfigRequest $request): array
    {
        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Get current config
        $config = $this->seasonRepository->getConfig();

        // Update config fields
        if ($request->source !== null) {
            $config['source'] = $request->source;
        }
        if ($request->simulatedDate !== null) {
            $config['simulated_date'] = $request->simulatedDate;
        }
        if ($request->year !== null) {
            $config['year'] = $request->year;
        }
        if ($request->startTime !== null) {
            $config['start_time'] = $request->startTime;
        }
        if ($request->finishTime !== null) {
            $config['finish_time'] = $request->finishTime;
        }
        if ($request->blackoutFrom !== null) {
            $config['blackout_from'] = $request->blackoutFrom;
        }
        if ($request->blackoutTo !== null) {
            $config['blackout_to'] = $request->blackoutTo;
        }

        // Save updated config
        $this->seasonRepository->updateConfig($config);

        // Sync in-memory TimeService so any same-request callers (e.g. ProcessSeasonUpdateUseCase)
        // use the updated time, not the stale value loaded at request startup.
        $timeSource = TimeSource::from($config['source']);
        $simulatedDate = isset($config['simulated_date'])
            ? new \DateTimeImmutable((string) $config['simulated_date'])
            : null;
        $this->timeService->setTimeSource($timeSource, $simulatedDate);

        $this->logger->info('admin.config_updated', array_filter([
            'source'         => $request->source,
            'simulated_date' => $request->simulatedDate,
            'year'           => $request->year,
            'start_time'     => $request->startTime,
            'finish_time'    => $request->finishTime,
            'blackout_from'  => $request->blackoutFrom,
            'blackout_to'    => $request->blackoutTo,
        ], fn($v) => $v !== null));

        return [
            'success' => true,
            'message' => 'Season configuration updated successfully',
        ];
    }
}
