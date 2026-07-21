<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\TransactionServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Delete User Use Case
 *
 * Permanently removes a user account together with its linked crew or boat
 * profile. Deleting the profile cascades (via FK constraints) to its
 * availability, history, and whitelist rows. Prevents admins from deleting
 * their own account.
 */
class DeleteUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private TransactionServiceInterface $transactionService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $targetUserId     ID of the user to delete
     * @param int $requestingUserId ID of the admin making the change
     * @throws ValidationException If an admin tries to delete their own account
     * @throws \RuntimeException   If the target user is not found
     */
    public function execute(int $targetUserId, int $requestingUserId): void
    {
        if ($targetUserId === $requestingUserId) {
            throw new ValidationException(['user_id' => 'You cannot delete your own account']);
        }

        $user = $this->userRepository->findById($targetUserId);

        if ($user === null) {
            throw new \RuntimeException("User with ID {$targetUserId} not found");
        }

        $this->transactionService->begin();

        try {
            $crew = $this->crewRepository->findByUserId($targetUserId);
            if ($crew !== null) {
                $this->crewRepository->delete($crew->getKey());
            }

            $boat = $this->boatRepository->findByOwnerUserId($targetUserId);
            if ($boat !== null) {
                $this->boatRepository->delete($boat->getKey());
            }

            $this->passwordResetTokenRepository->deleteByUserId($targetUserId);

            $this->userRepository->delete($targetUserId);

            $this->transactionService->commit();
        } catch (\Exception $e) {
            $this->transactionService->rollBack();
            throw $e;
        }

        $this->logger->info('admin.user_deleted', [
            'user_id'            => $targetUserId,
            'email'              => $user->getEmail(),
            'account_type'       => $user->getAccountType(),
            'deleted_by_user_id' => $requestingUserId,
        ]);
    }
}
