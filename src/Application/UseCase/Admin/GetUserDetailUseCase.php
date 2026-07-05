<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\Enum\CrewRankDimension;

/**
 * Get User Detail Use Case
 *
 * Returns a single user's info together with their linked crew profile (if any).
 */
class GetUserDetailUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CrewRepositoryInterface $crewRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param int $userId
     * @return array{user: array, crew: array|null}
     * @throws \RuntimeException If the user is not found
     */
    public function execute(int $userId): array
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            throw new \RuntimeException("User with ID {$userId} not found");
        }

        $crew = $this->crewRepository->findByUserId($userId);

        return [
            'user' => [
                'id'           => $user->getId(),
                'email'        => $user->getEmail(),
                'account_type' => $user->getAccountType(),
                'is_admin'     => $user->isAdmin(),
                'created_at'   => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
            'crew' => $crew ? [
                'key'             => $crew->getKey()->toString(),
                'first_name'      => $crew->getFirstName(),
                'last_name'       => $crew->getLastName(),
                'skill'           => $crew->getSkill()->value,
                'partner_key'     => $crew->getPartnerKey()?->toString(),
                'whitelist'       => $crew->getWhitelist(),
                'rank_commitment' => $crew->getRank()->getDimension(CrewRankDimension::COMMITMENT),
            ] : null,
        ];
    }
}
