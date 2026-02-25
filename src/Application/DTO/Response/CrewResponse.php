<?php

declare(strict_types=1);

namespace App\Application\DTO\Response;

use App\Domain\Entity\Crew;

/**
 * Crew Response DTO
 *
 * Data transfer object for crew information.
 */
final readonly class CrewResponse
{
    public function __construct(
        public string $key,
        public ?string $displayName,
        public string $firstName,
        public string $lastName,
        public ?string $partnerKey,
        public ?string $mobile,
        public bool $socialPreference,
        public ?string $membershipNumber,
        public int $skill,
        public ?string $experience,
        public array $rank,
        public array $availabilities,
        public array $history,
        public array $whitelist,
    ) {
    }

    /**
     * Create from Crew entity
     */
    public static function fromEntity(Crew $crew): self
    {
        return new self(
            key: $crew->getKey()->toString(),
            displayName: $crew->getDisplayName(),
            firstName: $crew->getFirstName(),
            lastName: $crew->getLastName(),
            partnerKey: $crew->getPartnerKey()?->toString(),
            mobile: $crew->getMobile(),
            socialPreference: $crew->hasSocialPreference(),
            membershipNumber: $crew->getMembershipNumber(),
            skill: $crew->getSkill()->value,
            experience: $crew->getExperience(),
            rank: $crew->getRank()->toArray(),
            availabilities: array_map(fn($s) => $s->value, $crew->getAllAvailability()),
            history: $crew->getAllHistory(),
            whitelist: $crew->getWhitelist(),
        );
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'displayName' => $this->displayName,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'partnerKey' => $this->partnerKey,
            'mobile' => $this->mobile,
            'socialPreference' => $this->socialPreference,
            'membershipNumber' => $this->membershipNumber,
            'skill' => $this->skill,
            'experience' => $this->experience,
            'rank' => $this->rank,
            'availabilities' => $this->availabilities,
            'history' => $this->history,
            'whitelist' => $this->whitelist,
        ];
    }
}
