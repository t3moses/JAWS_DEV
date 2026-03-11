<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\Enum\CrewRankDimension;
use App\Domain\ValueObject\CrewKey;
use Psr\Log\LoggerInterface;

/**
 * Set Crew Commitment Rank Use Case
 *
 * Allows admins to manually set the commitment rank for a crew member.
 *
 * Valid values:
 *   0 = unavailable/withdrawn (lowest priority)
 *   1 = admin penalty (manually deprioritised)
 *   2 = normal priority (available)
 *   3 = high priority (assigned to next event)
 */
class SetCrewCommitmentRankUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $crewKey  Crew identifier
     * @param int    $rank     Commitment rank (0–3)
     * @return array{crew_key: string, rank_commitment: int}
     * @throws CrewNotFoundException
     * @throws ValidationException
     */
    public function execute(string $crewKey, int $rank): array
    {
        if ($rank < 0 || $rank > 3) {
            throw new ValidationException(['commitment_rank' => 'Must be an integer between 0 and 3']);
        }

        $crew = $this->crewRepository->findByKey(CrewKey::fromString($crewKey));
        if ($crew === null) {
            throw new CrewNotFoundException("Crew not found: {$crewKey}");
        }

        $crew->setRankDimension(CrewRankDimension::COMMITMENT, $rank);
        $this->crewRepository->updateRankCommitment($crew);

        $this->logger->info('admin.commitment_rank_set', [
            'crew_key' => $crewKey,
            'rank'     => $rank,
        ]);

        return [
            'crew_key' => $crewKey,
            'rank_commitment' => $rank,
        ];
    }
}
