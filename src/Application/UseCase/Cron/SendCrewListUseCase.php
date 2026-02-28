<?php

declare(strict_types=1);

namespace App\Application\UseCase\Cron;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Domain\ValueObject\EventId;

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
                error_log("SendCrewListUseCase: boat {$boat['display_name']} has no linked owner account — skipping CC");
                $details[] = "Skipped boat {$boat['display_name']} (no linked owner account)";
                $skipped++;
                continue;
            }

            $ownerUser = $this->userRepository->findById($ownerUserId);
            if ($ownerUser === null) {
                error_log("SendCrewListUseCase: user {$ownerUserId} not found for boat {$boat['display_name']} — skipping CC");
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
        } else {
            error_log("SendCrewListUseCase: failed to send crew list email to {$this->adminEmail}");
            $details[] = "Failed to send crew list email";
        }

        return compact('sent', 'cc_count', 'skipped', 'details');
    }
}
