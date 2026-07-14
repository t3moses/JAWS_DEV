<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
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
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
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

        // Batch load crew/boat display names keyed by user_id to avoid N+1 queries
        $displayNameByUserId = [];
        foreach ($this->crewRepository->findAll() as $crew) {
            if ($crew->getUserId() !== null) {
                $displayNameByUserId[$crew->getUserId()] = $crew->getDisplayName();
            }
        }
        foreach ($this->boatRepository->findAll() as $boat) {
            if ($boat->getOwnerUserId() !== null) {
                $displayNameByUserId[$boat->getOwnerUserId()] = $boat->getDisplayName();
            }
        }

        return array_map(fn($user) => [
            'id'           => $user->getId(),
            'email'        => $user->getEmail(),
            'display_name' => $displayNameByUserId[$user->getId()] ?? null,
            'account_type' => $user->getAccountType(),
            'is_admin'     => $user->isAdmin(),
            'created_at'   => $user->getCreatedAt()->format('Y-m-d H:i:s'),
        ], $users);
    }
}
