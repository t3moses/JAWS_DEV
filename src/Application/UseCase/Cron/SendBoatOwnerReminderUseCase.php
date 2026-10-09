<?php

declare(strict_types=1);

namespace App\Application\UseCase\Cron;

use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Domain\ValueObject\EventId;
use Psr\Log\LoggerInterface;

/**
 * Send Boat Owner Reminder Use Case
 *
 * Sends an individual reminder email to each boat owner whose boat is
 * registered (has offered berths) for the event, approximately 24 hours
 * before the event start time.
 *
 * Triggered by cron job; idempotency is enforced by the caller (bin/notify.php)
 * via the cron_notifications table.
 */
class SendBoatOwnerReminderUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private BoatRepositoryInterface $boatRepository,
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

        // Load all boats registered (offered berths) for this event
        $boats = $this->boatRepository->findAvailableForEvent($eventId);

        if (empty($boats)) {
            $details[] = "No registered boats for event {$eventId->toString()}";
            return compact('sent', 'skipped', 'details');
        }

        $subject = "Reminder: Sail Friday tomorrow - {$eventId->toString()}";

        foreach ($boats as $boat) {
            $boatName = $boat->getDisplayName() ?? $boat->getKey()->toString();
            $ownerUserId = $boat->getOwnerUserId();

            if ($ownerUserId === null) {
                $details[] = "Skipped boat {$boatName} (no linked owner account)";
                $skipped++;
                continue;
            }

            $user = $this->userRepository->findById($ownerUserId);

            if ($user === null) {
                $details[] = "Skipped boat {$boatName} (owner user account not found)";
                $skipped++;
                continue;
            }

            $body = $this->emailTemplateService->renderBoatOwnerReminderNotification(
                $boat->getOwnerFirstName(),
                $boatName,
                $eventId->toString(),
                $eventData['event_date'],
                $eventData['start_time']
            );

            if ($this->emailService->send($user->getEmail(), $subject, $body)) {
                $sent++;
                $details[] = "Sent reminder to {$boat->getOwnerFirstName()} {$boat->getOwnerLastName()} ({$user->getEmail()}) for {$boatName}";
                $this->logger->info('email.sent', ['event_id' => $eventId->toString(), 'boat_key' => $boat->getKey()->toString(), 'to' => $user->getEmail()]);
            } else {
                $details[] = "Failed to send reminder to {$boat->getOwnerFirstName()} {$boat->getOwnerLastName()} ({$user->getEmail()}) for {$boatName}";
                $skipped++;
                $this->logger->warning('email.failed', ['event_id' => $eventId->toString(), 'boat_key' => $boat->getKey()->toString(), 'to' => $user->getEmail()]);
            }
        }

        return compact('sent', 'skipped', 'details');
    }
}
