<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use Psr\Log\LoggerInterface;

/**
 * Add To Crew Whitelist Use Case
 *
 * Adds a boat to a crew member's whitelist of preferred boats.
 */
class AddToCrewWhitelistUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param string $crewKey Crew key
     * @param string $boatKey Boat key to add
     * @return array Updated crew summary
     * @throws CrewNotFoundException If crew not found
     * @throws BoatNotFoundException If boat not found
     */
    public function execute(string $crewKey, string $boatKey): array
    {
        $crewKeyVO = CrewKey::fromString($crewKey);
        $boatKeyVO = BoatKey::fromString($boatKey);

        $crew = $this->crewRepository->findByKey($crewKeyVO);
        if ($crew === null) {
            throw new CrewNotFoundException("Crew member not found: {$crewKey}");
        }

        $boat = $this->boatRepository->findByKey($boatKeyVO);
        if ($boat === null) {
            throw new BoatNotFoundException($boatKeyVO);
        }

        $this->crewRepository->addToWhitelist($crewKeyVO, $boatKeyVO);

        $this->logger->info('admin.whitelist_added', [
            'crew_key' => $crewKey,
            'boat_key' => $boatKey,
        ]);

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
