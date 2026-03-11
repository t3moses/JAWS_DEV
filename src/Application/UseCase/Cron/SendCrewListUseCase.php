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
 * Send Crew List Use Case
 *
 * Sends the full flotilla crew list to the admin (TO) and all linked boat owners
 * (CC) when the blackout window commences on event day.
 *
 * Triggered by cron job; idempotency is enforced by the caller (bin/notify.php)
 * via the cron_notifications table.
 */
class SendCrewListUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private EmailTemplateServiceInterface $emailTemplateService,
        private string $adminEmail,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param EventId $eventId
     * @return array{sent: bool, cc_count: int, skipped: int, details: string[]}
     */
    public function execute(EventId $eventId): array
    {
        $sent = false;
        $cc_count = 0;
        $skipped = 0;
        $details = [];

        // Load event data
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            $details[] = "Event {$eventId->toString()} not found";
            return compact('sent', 'cc_count', 'skipped', 'details');
        }

        // Load flotilla
        $flotilla = $this->seasonRepository->getFlotilla($eventId);
        if ($flotilla === null || empty($flotilla['crewed_boats'])) {
            $details[] = "No flotilla or no crewed boats for event {$eventId->toString()}";
            return compact('sent', 'cc_count', 'skipped', 'details');
        }

        $crewedBoats = $flotilla['crewed_boats'];

        // Collect unique CC emails from boat owner user accounts
        $ccEmails = [];
        foreach ($crewedBoats as $crewedBoat) {
            $boat = $crewedBoat['boat'];
            $ownerUserId = (int)($boat['owner_user_id'] ?? 0);

            if ($ownerUserId === 0) {
                $details[] = "Skipped boat {$boat['display_name']} (no linked owner account)";
                $skipped++;
                continue;
            }

            $ownerUser = $this->userRepository->findById($ownerUserId);
            if ($ownerUser === null) {
                $details[] = "Skipped boat {$boat['display_name']} (owner user account not found)";
                $skipped++;
                continue;
            }

            $ownerEmail = $ownerUser->getEmail();
            if (!in_array($ownerEmail, $ccEmails, true)) {
                $ccEmails[] = $ownerEmail;
                $details[] = "CC: {$ownerEmail} (owner of {$boat['display_name']})";
            }
        }

        $cc_count = count($ccEmails);
        $subject = "Crew List for Social Day Cruising - {$eventId->toString()}";

        $body = $this->emailTemplateService->renderCrewListNotification(
            $eventId->toString(),
            $eventData['event_date'],
            $crewedBoats
        );

        if ($this->emailService->sendWithCc($this->adminEmail, $ccEmails, $subject, $body)) {
            $sent = true;
            $details[] = "Crew list sent to {$this->adminEmail} with {$cc_count} CC recipient(s)";
            $this->logger->info('email.sent', ['event_id' => $eventId->toString(), 'type' => 'crew_list', 'to' => $this->adminEmail, 'cc_count' => $cc_count]);
        } else {
            $details[] = "Failed to send crew list email";
            $this->logger->warning('email.failed', ['event_id' => $eventId->toString(), 'type' => 'crew_list', 'to' => $this->adminEmail]);
        }

        return compact('sent', 'cc_count', 'skipped', 'details');
    }
}
