<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Exception\UserNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Logout Use Case
 *
 * Handles user logout by updating the last_logout timestamp.
 *
 * Note: Since JWTs are stateless tokens stored client-side, actual token
 * invalidation must happen on the client by deleting the token from storage.
 * This use case provides server-side logout tracking for:
 * - Audit trails and compliance reporting
 * - Security analytics
 * - Foundation for future token revocation/reuse detection
 *
 * Client Responsibilities After Logout:
 * 1. Delete JWT token from localStorage/sessionStorage/cookie
 * 2. Redirect to login page
 * 3. Clear any cached user data
 */
class LogoutUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute logout
     *
     * Updates the user's last_logout timestamp to track when they logged out.
     *
     * @param int $userId User ID from JWT token
     * @return void
     * @throws UserNotFoundException If user not found
     */
    public function execute(int $userId): void
    {
        // Find user by ID
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new UserNotFoundException("User with ID {$userId} not found");
        }

        // Update last logout timestamp
        $user->updateLastLogout(new \DateTimeImmutable());

        // Persist to database
        $this->userRepository->save($user);

        $this->logger->info('auth.logout', ['user_id' => $userId]);
    }
}
