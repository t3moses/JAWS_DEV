<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\DTO\Request\ForgotPasswordRequest;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Forgot Password Use Case
 *
 * Issues a time-limited single-use password reset link via email.
 * Always returns successfully to prevent email enumeration.
 */
class ForgotPasswordUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasswordResetTokenRepositoryInterface $tokenRepository,
        private EmailServiceInterface $emailService,
        private EmailTemplateServiceInterface $emailTemplateService,
        private array $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute forgot-password flow
     *
     * @param ForgotPasswordRequest $request
     * @throws ValidationException If email format is invalid
     */
    public function execute(ForgotPasswordRequest $request): void
    {
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $user = $this->userRepository->findByEmail($request->email);
        if ($user === null) {
            // Enumeration protection: log and return silently
            $this->logger->info('auth.forgot_password.unknown_email', [
                'email' => $request->email,
            ]);
            return;
        }

        // Invalidate any prior tokens for this user
        $this->tokenRepository->deleteByUserId($user->getId());

        // Generate a 256-bit CSPRNG token; store only the SHA-256 hash
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = new \DateTimeImmutable('+1 hour');

        $this->tokenRepository->save($user->getId(), $tokenHash, $expiresAt);

        $resetUrl = rtrim($this->config['app']['url'], '/') . '/reset-password.html?token=' . urlencode($plainToken);

        try {
            $body = $this->emailTemplateService->renderPasswordResetNotification($resetUrl, $expiresAt);
            $this->emailService->send(
                to: $user->getEmail(),
                subject: 'Reset your password',
                body: $body,
            );
        } catch (\Throwable $e) {
            // Non-fatal: log but do not surface the error to the caller
            $this->logger->error('auth.forgot_password.email_failed', [
                'user_id' => $user->getId(),
                'error'   => $e->getMessage(),
            ]);
        }

        $this->logger->info('auth.forgot_password.sent', [
            'user_id' => $user->getId(),
        ]);
    }
}
