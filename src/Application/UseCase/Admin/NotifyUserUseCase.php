<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Notify User Use Case
 *
 * Sends an admin-composed message to a single user's account email address.
 * Mirrors the compose-message flow of SendCustomNotificationUseCase, but
 * targets one user rather than an event's participant groups.
 */
class NotifyUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param int    $targetUserId ID of the user to notify
     * @param string $subject      Email subject
     * @param string $message      Plain-text message body
     * @return array{emails_sent: int, message: string}
     * @throws ValidationException If subject or message is empty
     * @throws \RuntimeException   If the target user is not found
     */
    public function execute(int $targetUserId, string $subject, string $message): array
    {
        $errors = [];
        if (trim($subject) === '') {
            $errors['subject'] = 'Subject is required';
        }
        if (trim($message) === '') {
            $errors['message'] = 'Message is required';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $user = $this->userRepository->findById($targetUserId);
        if ($user === null) {
            throw new \RuntimeException("User with ID {$targetUserId} not found");
        }

        $htmlBody  = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $fromEmail = getenv('EMAIL_FROM') ?: 'noreply@example.com';
        $fromName  = getenv('EMAIL_FROM_NAME') ?: 'NSC Sail Friday';

        $sent = $this->emailService->send($user->getEmail(), $subject, $htmlBody, $fromName, $fromEmail);

        if (!$sent) {
            $this->logger->warning('email.failed', [
                'user_id' => $targetUserId,
                'type'    => 'notify_user',
            ]);

            return [
                'emails_sent' => 0,
                'message'     => 'The notification email could not be sent',
            ];
        }

        $this->logger->info('email.sent', [
            'user_id' => $targetUserId,
            'type'    => 'notify_user',
        ]);

        return [
            'emails_sent' => 1,
            'message'     => "Notification sent to {$user->getEmail()}",
        ];
    }
}
