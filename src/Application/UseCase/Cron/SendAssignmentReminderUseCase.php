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
 * Send Assignment Reminder Use Case
 *
 * Sends an individual email to each crew member assigned to a boat in the
 * persisted flotilla, when the blackout window opens on event day. The email
 * names the assigned boat and its owner, reminds the crew to arrive by the
 * start time, and warns that absence at the start time is flagged as a no-show.
 *
 * Triggered by cron job; idempotency is enforced by the caller (bin/notify.php)
 * via the cron_notifications table.
 */
class SendAssignmentReminderUseCase
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

        $subject = "Your boat assignment for Sail Friday - {$eventId->toString()}";

        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $boat = $crewedBoat['boat'];
            $boatDisplayName = (string)($boat['display_name'] ?? '');
            $ownerFirstName  = (string)($boat['owner_first_name'] ?? '');

            foreach ($crewedBoat['crews'] as $crew) {
                $crewName = trim(($crew['first_name'] ?? '') . ' ' . ($crew['last_name'] ?? ''));
                $userId   = $crew['user_id'] ?? null;

                if ($userId === null) {
                    $details[] = "Skipped crew {$crewName} (no linked user account)";
                    $skipped++;
                    continue;
                }

                $user = $this->userRepository->findById((int)$userId);

                if ($user === null) {
                    $details[] = "Skipped crew {$crewName} (user account not found)";
                    $skipped++;
                    continue;
                }

                $body = $this->emailTemplateService->renderAssignmentReminderNotification(
                    (string)($crew['first_name'] ?? ''),
                    $boatDisplayName,
                    $ownerFirstName,
                    $eventId->toString(),
                    $eventData['event_date'],
                    $eventData['start_time']
                );

                if ($this->emailService->send($user->getEmail(), $subject, $body)) {
                    $sent++;
                    $details[] = "Sent assignment reminder to {$crewName} ({$user->getEmail()}) for {$boatDisplayName}";
                    $this->logger->info('email.sent', [
                        'event_id' => $eventId->toString(),
                        'type' => 'assignment_reminder',
                        'crew_key' => $crew['key'] ?? null,
                        'to' => $user->getEmail(),
                    ]);
                } else {
                    $details[] = "Failed to send assignment reminder to {$crewName} ({$user->getEmail()})";
                    $skipped++;
                    $this->logger->warning('email.failed', [
                        'event_id' => $eventId->toString(),
                        'type' => 'assignment_reminder',
                        'crew_key' => $crew['key'] ?? null,
                        'to' => $user->getEmail(),
                    ]);
                }
            }
        }

        return compact('sent', 'skipped', 'details');
    }
}
