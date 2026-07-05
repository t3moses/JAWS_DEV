<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Enum\BoatRankDimension;
use App\Domain\Enum\CrewRankDimension;

/**
 * Rank Value Object
 *
 * Immutable multi-dimensional rank tensor for boats and crews.
 * Boats have 2 dimensions: [flexibility, absence]
 * Crews have 4 dimensions: [availability, commitment, membership, absence]
 *
 * Rankings are compared lexicographically (left to right).
 * Higher rank values = higher priority (SelectionService sorts descending).
 */
final readonly class Rank
{
    /**
     * @param array<int, int> $values Rank values indexed by dimension
     */
    private function __construct(
        private array $values
    ) {
    }

    /**
     * Create boat rank (2D)
     *
     * @param int $flexibility 0=flexible (owner is crew), 1=inflexible
     * @param int $absence Count of past no-shows
     */
    public static function forBoat(int $flexibility, int $absence): self
    {
        return new self([
            BoatRankDimension::FLEXIBILITY->value => $flexibility,
            BoatRankDimension::ABSENCE->value => $absence,
        ]);
    }

    /**
     * Create crew rank (4D)
     *
     * @param int $availability 0-1: availability for event (0=not available, 1=selected/participated)
     * @param int $commitment 0-2: admin-set crew commitment/priority
     * @param int $membership 0=non-member, 1=member
     * @param int $absence Count of past no-shows
     */
    public static function forCrew(
        int $availability,
        int $commitment,
        int $membership,
        int $absence
    ): self {
        return new self([
            CrewRankDimension::AVAILABILITY->value => $availability,
            CrewRankDimension::COMMITMENT->value => $commitment,
            CrewRankDimension::MEMBERSHIP->value => $membership,
            CrewRankDimension::ABSENCE->value => $absence,
        ]);
    }

    /**
     * Create from array (for legacy compatibility)
     *
     * @param array<int, int> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Get value for specific dimension
     */
    public function getDimension(BoatRankDimension|CrewRankDimension $dimension): int
    {
        return $this->values[$dimension->value] ?? 0;
    }

    /**
     * Get all values as array
     *
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Set value for a dimension (returns new instance)
     */
    public function withDimension(BoatRankDimension|CrewRankDimension $dimension, int $value): self
    {
        $newValues = $this->values;
        $newValues[$dimension->value] = $value;
        return new self($newValues);
    }

    /**
     * Lexicographic comparison with another rank
     *
     * Returns:
     * - negative if $this < $other (other has higher priority — higher value wins)
     * - 0 if equal
     * - positive if $this > $other (this has higher priority — higher value wins)
     *
     * @param Rank $other
     * @return int
     */
    public function compareTo(self $other): int
    {
        $maxDimensions = max(count($this->values), count($other->values));

        for ($i = 0; $i < $maxDimensions; $i++) {
            $thisValue = $this->values[$i] ?? 0;
            $otherValue = $other->values[$i] ?? 0;

            $diff = $thisValue - $otherValue;
            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }

    /**
     * Check if this rank is greater than another (higher priority — higher value wins)
     */
    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Check if this rank is less than another (lower priority — higher value wins)
     */
    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Check if equal to another rank
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * String representation for debugging
     */
    public function __toString(): string
    {
        return '[' . implode(', ', $this->values) . ']';
    }
}
