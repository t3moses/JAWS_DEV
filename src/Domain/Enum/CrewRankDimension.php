<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Crew Rank Dimensions
 *
 * Defines the dimensions used in multi-dimensional ranking for crews.
 * Rankings are compared lexicographically (left to right) during sorting.
 * Higher rank values = higher priority.
 *
 * AVAILABILITY (0) is the most significant dimension: crews with availability=1
 * (selected for/participated in previous event) rank higher than availability=0.
 */
enum CrewRankDimension: int
{
    case AVAILABILITY = 0; // 0-1: availability for event (0=not available, 1=selected/participated)
    case COMMITMENT = 1;   // 0-2: admin-set crew commitment/priority
    case MEMBERSHIP = 2;   // 0=non-member, 1=member
    case ABSENCE = 3;      // Count of past no-shows

    /**
     * Get all crew rank dimensions in order (4D)
     *
     * @return array<CrewRankDimension>
     */
    public static function all(): array
    {
        return [
            self::AVAILABILITY,
            self::COMMITMENT,
            self::MEMBERSHIP,
            self::ABSENCE,
        ];
    }
}
