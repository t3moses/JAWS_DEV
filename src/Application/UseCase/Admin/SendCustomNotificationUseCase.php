<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Domain\ValueObject\EventId;

/**
 * Send Custom Notification Use Case
 *
 * Sends an admin-composed message to selected participant groups via BCC.
 * Batches emails in chunks of 50 to stay within limits of many services.
 */
class SendCustomNotificationUseCase
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private BoatRepositoryInterface $boatRepository,
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
    ) {
    }

    /**
     * @param EventId $eventId
     * @param string $subject
     * @param string $message Plain-text message body
     * @param bool $sendToBoatOwners
     * @param bool $sendToCrew
     * @return array{emails_sent: int, message: string}
     * @throws EventNotFoundException
     * @throws ValidationException
     */
    public function execute(
        EventId $eventId,
        string $subject,
        string $message,
        bool $sendToBoatOwners,
        bool $sendToCrew
    ): array {
        // Validate inputs
        $errors = [];
        if (trim($subject) === '') {
            $errors['subject'] = 'Subject is required';
        }
        if (trim($message) === '') {
            $errors['message'] = 'Message is required';
        }
        if (!$sendToBoatOwners && !$sendToCrew) {
            $errors['recipients'] = 'At least one recipient group must be selected';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Verify event exists
        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            throw new EventNotFoundException($eventId);
        }

        // Resolve emails using the same helper logic
        $helper = new GetParticipantEmailsUseCase(
            $this->boatRepository,
            $this->crewRepository,
            $this->eventRepository,
            $this->userRepository
        );

        $allEmails = [];
        if ($sendToBoatOwners) {
            $allEmails += $helper->resolveBoatOwnerEmails($eventId);
        }
        if ($sendToCrew) {
            $allEmails += $helper->resolveCrewMemberEmails($eventId);
        }

        $allEmails = array_values($allEmails);
        $htmlBody  = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $fromEmail = getenv('EMAIL_FROM') ?: 'noreply@example.com';
        $fromName  = getenv('EMAIL_FROM_NAME') ?: 'NSC Social Day Cruising';

        $batches    = array_chunk($allEmails, self::BATCH_SIZE);
        $totalSent  = 0;

        foreach ($batches as $batch) {
            if ($this->emailService->sendWithBcc($fromEmail, $batch, $subject, $htmlBody, $fromName, $fromEmail)) {
                $totalSent += count($batch);
            } else {
                error_log("SendCustomNotificationUseCase: BCC batch of " . count($batch) . " failed");
            }
        }

        return [
            'emails_sent' => $totalSent,
            'message'     => "Sent {$totalSent} notification emails",
        ];
    }
}
