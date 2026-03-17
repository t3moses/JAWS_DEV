<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\DTO\Request\ResetPasswordRequest;
use App\Application\Exception\InvalidResetTokenException;
use App\Application\Exception\ValidationException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Reset Password Use Case
 *
 * Validates a reset token and updates the user's password.
 * Token is deleted immediately after use (single-use enforcement).
 */
class ResetPasswordUseCase
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $tokenRepository,
        private UserRepositoryInterface $userRepository,
        private PasswordServiceInterface $passwordService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute password reset
     *
     * @param ResetPasswordRequest $request
     * @throws ValidationException If token or password fields are missing
     * @throws InvalidResetTokenException If token is unknown or expired
     * @throws WeakPasswordException If new password does not meet requirements
     */
    public function execute(ResetPasswordRequest $request): void
    {
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $tokenHash = hash('sha256', $request->token);
        $record    = $this->tokenRepository->findByTokenHash($tokenHash);

        if ($record === null) {
            $this->logger->warning('auth.reset_password.invalid_token', [
                'reason' => 'unknown_token',
            ]);
            throw new InvalidResetTokenException();
        }

        $expiresAt = new \DateTimeImmutable($record['expires_at']);
        if ($expiresAt < new \DateTimeImmutable()) {
            $this->tokenRepository->deleteByTokenHash($tokenHash);
            $this->logger->warning('auth.reset_password.invalid_token', [
                'reason'  => 'expired',
                'user_id' => $record['user_id'],
            ]);
            throw new InvalidResetTokenException('Reset token has expired');
        }

        if (!$this->passwordService->meetsRequirements($request->password)) {
            throw new WeakPasswordException($this->passwordService->getRequirementsMessage());
        }

        $user = $this->userRepository->findById((int)$record['user_id']);
        if ($user === null) {
            // Token references a deleted user — clean up and reject
            $this->tokenRepository->deleteByTokenHash($tokenHash);
            $this->logger->warning('auth.reset_password.invalid_token', [
                'reason'  => 'orphaned_token',
                'user_id' => $record['user_id'],
            ]);
            throw new InvalidResetTokenException();
        }

        $user->setPasswordHash($this->passwordService->hash($request->password));
        $this->userRepository->save($user);

        // Single-use enforcement
        $this->tokenRepository->deleteByTokenHash($tokenHash);

        $this->logger->info('auth.reset_password.success', [
            'user_id' => $user->getId(),
        ]);
    }
}
