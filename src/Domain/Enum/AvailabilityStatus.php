<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Availability Status
 *
 * Defines the possible availability states for crew members for each event.
 */
enum AvailabilityStatus: int
{
    case UNAVAILABLE = 0;  // Cannot participate
    case AVAILABLE = 1;    // Can participate
    case GUARANTEED = 2;   // Selected for event
    case WITHDRAWN = 3;    // Explicitly withdrawn

    /**
     * Check if status means crew can participate
     */
    public function canParticipate(): bool
    {
        return $this === self::AVAILABLE || $this === self::GUARANTEED;
    }

    /**
     * Check if status means crew is assigned to a boat
     */
    public function isAssigned(): bool
    {
        return $this === self::GUARANTEED;
    }
}
