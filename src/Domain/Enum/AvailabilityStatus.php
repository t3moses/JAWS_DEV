<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Availability Status (Compressed 0-1)
 *
 * After event completion, crew_availability.status is set to indicate selection for next event:
 * 0 = registered available but not selected (or not registered)
 * 1 = registered available and selected for next event (guaranteed assignment rank)
 */
enum AvailabilityStatus: int
{
    case NOT_SELECTED = 0;  // Available but not selected for next event
    case SELECTED = 1;      // Selected for next event

    public function isSelected(): bool
    {
        return $this === self::SELECTED;
    }
}
