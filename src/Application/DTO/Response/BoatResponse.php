<?php

declare(strict_types=1);

namespace App\Application\DTO\Response;

use App\Domain\Entity\Boat;

/**
 * Boat Response DTO
 *
 * Data transfer object for boat information.
 */
final readonly class BoatResponse
{
    public function __construct(
        public string $key,
        public ?string $displayName,
        public string $ownerFirstName,
        public string $ownerLastName,
        public string $ownerMobile,
        public int $minBerths,
        public int $maxBerths,
        public bool $assistanceRequired,
        public bool $socialPreference,
        public bool $willingToCrew,
        public array $rank,
        public array $availabilities,
        public array $history,
    ) {
    }

    /**
     * Create from Boat entity
     */
    public static function fromEntity(Boat $boat): self
    {
        return new self(
            key: $boat->getKey()->toString(),
            displayName: $boat->getDisplayName(),
            ownerFirstName: $boat->getOwnerFirstName(),
            ownerLastName: $boat->getOwnerLastName(),
            ownerMobile: $boat->getOwnerMobile(),
            minBerths: $boat->getMinBerths(),
            maxBerths: $boat->getMaxBerths(),
            assistanceRequired: $boat->requiresAssistance(),
            socialPreference: $boat->hasSocialPreference(),
            willingToCrew: $boat->isWillingToCrew(),
            rank: $boat->getRank()->toArray(),
            availabilities: $boat->getAllBerths(),
            history: $boat->getAllHistory(),
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
            'ownerFirstName' => $this->ownerFirstName,
            'ownerLastName' => $this->ownerLastName,
            'ownerMobile' => $this->ownerMobile,
            'minBerths' => $this->minBerths,
            'maxBerths' => $this->maxBerths,
            'assistanceRequired' => $this->assistanceRequired,
            'socialPreference' => $this->socialPreference,
            'willingToCrew' => $this->willingToCrew,
            'rank' => $this->rank,
            'availabilities' => $this->availabilities,
            'history' => $this->history,
        ];
    }
}
