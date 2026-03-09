<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\CrewKey;
use Psr\Log\LoggerInterface;

/**
 * Update Crew Profile Use Case
 *
 * Allows admins to update skill level and/or partner assignment for a crew member.
 */
class UpdateCrewProfileUseCase
{
    public function __construct(
        private CrewRepositoryInterface $crewRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute the use case
     *
     * @param string   $crewKey    Crew key to update
     * @param int|null $skill      Skill level (0=Novice, 1=Intermediate, 2=Advanced) or null to leave unchanged
     * @param string|null $partnerKey Partner crew key or null to clear partner
     * @param bool $clearPartner   True to explicitly clear the partner (pass null for $partnerKey)
     * @return array Updated crew summary
     * @throws CrewNotFoundException If crew not found
     * @throws ValidationException   If skill value is invalid or partner key does not exist
     */
    public function execute(string $crewKey, ?int $skill, ?string $partnerKey, bool $clearPartner = false): array
    {
        $crew = $this->crewRepository->findByKey(CrewKey::fromString($crewKey));

        if ($crew === null) {
            throw new CrewNotFoundException("Crew member not found: {$crewKey}");
        }

        if ($skill !== null) {
            if (!in_array($skill, [0, 1, 2], true)) {
                throw new ValidationException(['skill' => 'Skill must be 0 (Novice), 1 (Intermediate), or 2 (Advanced)']);
            }
            $crew->setSkill(SkillLevel::from($skill));
        }

        if ($clearPartner) {
            $crew->setPartnerKey(null);
        } elseif ($partnerKey !== null) {
            $partnerCrewKey = CrewKey::fromString($partnerKey);
            $partner = $this->crewRepository->findByKey($partnerCrewKey);
            if ($partner === null) {
                throw new ValidationException(['partner_key' => "Partner crew member not found: {$partnerKey}"]);
            }
            $crew->setPartnerKey($partnerCrewKey);
        }

        $this->crewRepository->save($crew);

        $this->logger->info('admin.crew_profile_updated', [
            'crew_key'    => $crewKey,
            'skill'       => $crew->getSkill()->value,
            'partner_key' => $crew->getPartnerKey()?->toString(),
        ]);

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
