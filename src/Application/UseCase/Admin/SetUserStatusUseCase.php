<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use Psr\Log\LoggerInterface;

/**
 * Set User Status Use Case
 *
 * Suspends (disables) or reactivates a user account. Suspension is reversible:
 * a disabled account is blocked from logging in, has existing tokens rejected,
 * and its linked crew/boat is excluded from selection and flotillas — but all
 * data is preserved and the account can be reactivated at any time.
 *
 * Prevents admins from deactivating their own account.
 *
 * After the change, the season pipeline is re-run so flotillas re-balance with
 * the account excluded (on disable) or re-included (on reactivate).
 */
class SetUserStatusUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ProcessSeasonUpdateUseCase $processSeasonUpdateUseCase,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int  $targetUserId     ID of the user to update
     * @param bool $disabled         True to suspend, false to reactivate
     * @param int  $requestingUserId ID of the admin making the change
     * @return array Updated user summary plus the season recalculation result
     * @throws ValidationException If an admin tries to deactivate their own account
     * @throws \RuntimeException   If the target user is not found
     */
    public function execute(int $targetUserId, bool $disabled, int $requestingUserId): array
    {
        if ($targetUserId === $requestingUserId) {
            throw new ValidationException(['user_id' => 'You cannot change your own account status']);
        }

        $user = $this->userRepository->findById($targetUserId);

        if ($user === null) {
            throw new \RuntimeException("User with ID {$targetUserId} not found");
        }

        if ($disabled) {
            $user->disable(new \DateTimeImmutable());
        } else {
            $user->reactivate();
        }

        $this->userRepository->save($user);

        $this->logger->info('admin.user_status_set', [
            'user_id'            => $targetUserId,
            'email'              => $user->getEmail(),
            'disabled'           => $user->isDisabled(),
            'changed_by_user_id' => $requestingUserId,
        ]);

        // Re-run the season pipeline so flotillas reflect the change immediately.
        $recalculation = [];
        try {
            $recalculation = $this->processSeasonUpdateUseCase->execute();
        } catch (\Exception $e) {
            $recalculation = ['error' => $e->getMessage()];
        }

        return [
            'id'           => $user->getId(),
            'email'        => $user->getEmail(),
            'account_type' => $user->getAccountType(),
            'is_admin'     => $user->isAdmin(),
            'disabled'     => $user->isDisabled(),
            'disabled_at'  => $user->getDisabledAt()?->format('Y-m-d H:i:s'),
            'recalculation' => $recalculation,
        ];
    }
}
