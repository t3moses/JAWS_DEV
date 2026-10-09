<?php

declare(strict_types=1);

namespace App\Application\UseCase\Cron;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Domain\ValueObject\EventId;
use Psr\Log\LoggerInterface;

/**
 * Send Boat Owner Crew List Use Case
 *
 * Sends an individual email to each boat owner in the persisted flotilla when
 * the blackout window opens on event day. Each email lists the display names of
 * the crew assigned to that owner's boat and closes by looking forward to
 * seeing them at the start time.
 *
 * Triggered by cron job; idempotency is enforced by the caller (bin/notify.php)
 * via the cron_notifications table.
 */
class SendBoatOwnerCrewListUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private EmailTemplateServiceInterface $emailTemplateService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param EventId $eventId
     * @return array{sent: int, skipped: int, details: string[]}
     */
    public function execute(EventId $eventId): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        // Load event data
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            $details[] = "Event {$eventId->toString()} not found";
            return compact('sent', 'skipped', 'details');
        }

        // Load flotilla
        $flotilla = $this->seasonRepository->getFlotilla($eventId);
        if ($flotilla === null || empty($flotilla['crewed_boats'])) {
            $details[] = "No flotilla or no crewed boats for event {$eventId->toString()}";
            return compact('sent', 'skipped', 'details');
        }

        $subject = "Your crew list for Sail Friday - {$eventId->toString()}";

        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $boat = $crewedBoat['boat'];
            $boatDisplayName = (string)($boat['display_name'] ?? '');
            $ownerUserId = $boat['owner_user_id'] ?? null;

            if ($ownerUserId === null) {
                $details[] = "Skipped boat {$boatDisplayName} (no linked owner account)";
                $skipped++;
                continue;
            }

            $user = $this->userRepository->findById((int)$ownerUserId);

            if ($user === null) {
                $details[] = "Skipped boat {$boatDisplayName} (owner user account not found)";
                $skipped++;
                continue;
            }

            $crewDisplayNames = [];
            foreach ($crewedBoat['crews'] as $crew) {
                $displayName = $crew['display_name'] ?? null;
                if ($displayName === null || trim((string)$displayName) === '') {
                    $displayName = trim(($crew['first_name'] ?? '') . ' ' . ($crew['last_name'] ?? ''));
                }
                if ($displayName !== '') {
                    $crewDisplayNames[] = (string)$displayName;
                }
            }

            $body = $this->emailTemplateService->renderBoatOwnerCrewListNotification(
                (string)($boat['owner_first_name'] ?? ''),
                $boatDisplayName,
                $crewDisplayNames,
                $eventId->toString(),
                $eventData['event_date'],
                $eventData['start_time']
            );

            if ($this->emailService->send($user->getEmail(), $subject, $body)) {
                $sent++;
                $details[] = "Sent crew list to {$boat['owner_first_name']} {$boat['owner_last_name']} ({$user->getEmail()}) for {$boatDisplayName}";
                $this->logger->info('email.sent', [
                    'event_id' => $eventId->toString(),
                    'type' => 'owner_crew_list',
                    'boat_key' => $boat['key'] ?? null,
                    'to' => $user->getEmail(),
                ]);
            } else {
                $details[] = "Failed to send crew list to {$boat['owner_first_name']} {$boat['owner_last_name']} ({$user->getEmail()}) for {$boatDisplayName}";
                $skipped++;
                $this->logger->warning('email.failed', [
                    'event_id' => $eventId->toString(),
                    'type' => 'owner_crew_list',
                    'boat_key' => $boat['key'] ?? null,
                    'to' => $user->getEmail(),
                ]);
            }
        }

        return compact('sent', 'skipped', 'details');
    }
}
