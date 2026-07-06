<?php

declare(strict_types=1);

namespace App\Application\UseCase\Cron;

use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Domain\ValueObject\EventId;
use Psr\Log\LoggerInterface;

/**
 * Send Crew Reminder Use Case
 *
 * Sends an individual reminder email to each registered crew member
 * approximately 24 hours before the event start time.
 *
 * Triggered by cron job; idempotency is enforced by the caller (bin/notify.php)
 * via the cron_notifications table.
 */
class SendCrewReminderUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private CrewRepositoryInterface $crewRepository,
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

        // Load all registered crew (any crew_availability record)
        $crews = $this->crewRepository->findAvailableForEvent($eventId);

        if (empty($crews)) {
            $details[] = "No registered crew for event {$eventId->toString()}";
            return compact('sent', 'skipped', 'details');
        }

        $subject = "Reminder: Social Day Cruising tomorrow - {$eventId->toString()}";

        foreach ($crews as $crew) {
            $userId = $crew->getUserId();

            if ($userId === null) {
                $details[] = "Skipped crew {$crew->getFirstName()} {$crew->getLastName()} (no linked user account)";
                $skipped++;
                continue;
            }

            $user = $this->userRepository->findById($userId);

            if ($user === null) {
                $details[] = "Skipped crew {$crew->getFirstName()} {$crew->getLastName()} (user account not found)";
                $skipped++;
                continue;
            }

            $body = $this->emailTemplateService->renderCrewReminderNotification(
                $crew->getFirstName(),
                $eventId->toString(),
                $eventData['event_date'],
                $eventData['start_time']
            );

            if ($this->emailService->send($user->getEmail(), $subject, $body)) {
                $sent++;
                $details[] = "Sent reminder to {$crew->getFirstName()} {$crew->getLastName()} ({$user->getEmail()})";
                $this->logger->info('email.sent', ['event_id' => $eventId->toString(), 'crew_key' => $crew->getKey()->toString(), 'to' => $user->getEmail()]);
            } else {
                $details[] = "Failed to send reminder to {$crew->getFirstName()} {$crew->getLastName()} ({$user->getEmail()})";
                $skipped++;
                $this->logger->warning('email.failed', ['event_id' => $eventId->toString(), 'crew_key' => $crew->getKey()->toString(), 'to' => $user->getEmail()]);
            }
        }

        return compact('sent', 'skipped', 'details');
    }
}
