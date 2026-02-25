<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\FlotillaNotFoundException;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\Port\Service\CalendarServiceInterface;
use App\Domain\ValueObject\EventId;

/**
 * Send Notifications Use Case
 *
 * Sends email notifications and calendar invites to participants for an event.
 * This is an admin function typically triggered after flotilla assignments are finalized.
 */
class SendNotificationsUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private EmailTemplateServiceInterface $emailTemplateService,
        private CalendarServiceInterface $calendarService,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param EventId $eventId
     * @param bool $includeCalendar Whether to include calendar attachments
     * @return array{success: bool, emails_sent: int, message: string}
     * @throws EventNotFoundException
     * @throws FlotillaNotFoundException
     */
    public function execute(EventId $eventId, bool $includeCalendar = true): array
    {
        // Verify event exists
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            throw new EventNotFoundException($eventId);
        }

        // Get flotilla data
        $flotilla = $this->seasonRepository->getFlotilla($eventId);
        if ($flotilla === null) {
            throw new FlotillaNotFoundException($eventId);
        }

        $emailsSent = 0;

        // Send notifications to boat owners with crew assignments
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $boat = $crewedBoat['boat'];
            $crews = $crewedBoat['crews'];

            // Generate calendar invite if requested
            $calendarAttachment = null;
            if ($includeCalendar) {
                try {
                    // Build crew description for calendar
                    $crewNames = array_map(
                        fn($crew) => $crew['first_name'] . ' ' . $crew['last_name'],
                        $crews
                    );
                    $description = 'Crew: ' . implode(', ', $crewNames);

                    // Convert event_date string to DateTime if needed
                    if ($eventData['event_date'] instanceof \DateTimeInterface) {
                        $eventDate = $eventData['event_date'];
                    } else {
                        // Try parsing as date string (YYYY-MM-DD format)
                        $eventDate = \DateTimeImmutable::createFromFormat('Y-m-d', $eventData['event_date']);
                        if ($eventDate === false) {
                            // Fall back to standard parsing
                            $eventDate = new \DateTimeImmutable($eventData['event_date']);
                        }
                    }

                    $calendarAttachment = $this->calendarService->generateEventCalendar(
                        $eventId,
                        $eventDate,
                        $eventData['start_time'],
                        $eventData['finish_time'],
                        $boat['display_name'],
                        $description
                    );
                } catch (\Exception $e) {
                    // Log error but continue without calendar attachment
                    error_log("Failed to generate calendar: " . $e->getMessage());
                    $calendarAttachment = null;
                }
            }

            // Send email to boat owner
            $subject = "Boat Assignment for {$eventId->toString()}";
            $body = $this->emailTemplateService->renderAssignmentNotification(
                $boat['owner_first_name'],
                $eventId->toString(),
                $boat['display_name'],
                $crews
            );

            $ownerUser = $this->userRepository->findById((int)($boat['owner_user_id'] ?? 0));
            $ownerEmail = $ownerUser?->getEmail() ?? '';

            // Note: Calendar attachments require direct PHPMailer access
            // For now, we send without attachments when using the simple send() method
            // TODO: Extend EmailServiceInterface to support attachments
            if ($this->emailService->send(
                $ownerEmail,
                $subject,
                $body
            )) {
                $emailsSent++;
            } else {
                error_log("Failed to send email to boat owner: {$ownerEmail}");
            }

            // Send email to each crew member
            foreach ($crews as $crew) {
                $body = $this->emailTemplateService->renderAssignmentNotification(
                    $crew['first_name'],
                    $eventId->toString(),
                    $boat['display_name'],
                    $crews
                );

                $crewUser = $this->userRepository->findById((int)($crew['user_id'] ?? 0));
                $crewEmail = $crewUser?->getEmail() ?? '';

                if ($this->emailService->send(
                    $crewEmail,
                    $subject,
                    $body
                )) {
                    $emailsSent++;
                } else {
                    error_log("Failed to send email to crew member: {$crewEmail}");
                }
            }
        }

        return [
            'success' => true,
            'emails_sent' => $emailsSent,
            'message' => "Sent {$emailsSent} notification emails for event {$eventId->toString()}",
        ];
    }

}
