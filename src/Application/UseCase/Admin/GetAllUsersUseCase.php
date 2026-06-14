<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Port\Repository\UserRepositoryInterface;

/**
 * Get All Users Use Case
 *
 * Returns a safe summary of all registered users for admin management.
 * Never exposes password hashes.
 */
class GetAllUsersUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @return array[] Array of user summary arrays (no password_hash)
     */
    public function execute(): array
    {
        $users = $this->userRepository->findAll();

        return array_map(fn($user) => [
            'id'           => $user->getId(),
            'email'        => $user->getEmail(),
            'account_type' => $user->getAccountType(),
            'is_admin'     => $user->isAdmin(),
            'disabled'     => $user->isDisabled(),
            'disabled_at'  => $user->getDisabledAt()?->format('Y-m-d H:i:s'),
            'created_at'   => $user->getCreatedAt()->format('Y-m-d H:i:s'),
        ], $users);
    }
}
