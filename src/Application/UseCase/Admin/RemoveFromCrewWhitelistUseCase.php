<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;

/**
 * Remove From Crew Whitelist Use Case
 *
 * Removes a boat from a crew member's whitelist of preferred boats.
 */
class RemoveFromCrewWhitelistUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param string $crewKey Crew key
     * @param string $boatKey Boat key to remove
     * @return array Updated crew summary
     * @throws CrewNotFoundException If crew not found
     */
    public function execute(string $crewKey, string $boatKey): array
    {
        $crewKeyVO = CrewKey::fromString($crewKey);
        $boatKeyVO = BoatKey::fromString($boatKey);

        $crew = $this->crewRepository->findByKey($crewKeyVO);
        if ($crew === null) {
            throw new CrewNotFoundException("Crew member not found: {$crewKey}");
        }

        $this->crewRepository->removeFromWhitelist($crewKeyVO, $boatKeyVO);

        // Reload to get updated whitelist
        $crew = $this->crewRepository->findByKey($crewKeyVO);

        return [
            'key'         => $crew->getKey()->toString(),
            'first_name'  => $crew->getFirstName(),
            'last_name'   => $crew->getLastName(),
            'skill'       => $crew->getSkill()->value,
            'partner_key' => $crew->getPartnerKey()?->toString(),
            'whitelist'   => $crew->getWhitelist(),
        ];
    }
}
