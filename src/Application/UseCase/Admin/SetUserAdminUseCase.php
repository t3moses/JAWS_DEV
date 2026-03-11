<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Set User Admin Use Case
 *
 * Grants or revokes admin privileges for a user.
 * Prevents admins from modifying their own admin status.
 */
class SetUserAdminUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int  $targetUserId     ID of the user to update
     * @param bool $isAdmin          Whether to grant (true) or revoke (false) admin
     * @param int  $requestingUserId ID of the admin making the change
     * @return array Updated user summary (no password_hash)
     * @throws ValidationException   If an admin tries to modify their own status
     * @throws \RuntimeException     If the target user is not found
     */
    public function execute(int $targetUserId, bool $isAdmin, int $requestingUserId): array
    {
        if ($targetUserId === $requestingUserId) {
            throw new ValidationException(['user_id' => 'You cannot change your own admin status']);
        }

        $user = $this->userRepository->findById($targetUserId);

        if ($user === null) {
            throw new \RuntimeException("User with ID {$targetUserId} not found");
        }

        $user->setIsAdmin($isAdmin);
        $this->userRepository->save($user);

        $this->logger->info('admin.user_admin_set', [
            'user_id'             => $targetUserId,
            'email'               => $user->getEmail(),
            'is_admin'            => $isAdmin,
            'changed_by_user_id'  => $requestingUserId,
        ]);

        return [
            'id'           => $user->getId(),
            'email'        => $user->getEmail(),
            'account_type' => $user->getAccountType(),
            'is_admin'     => $user->isAdmin(),
            'created_at'   => $user->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
