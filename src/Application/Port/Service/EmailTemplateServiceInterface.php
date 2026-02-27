<?php

declare(strict_types=1);

namespace App\Application\Port\Service;

use App\Domain\Entity\User;

/**
 * Email Template Service Interface
 *
 * Provides email template rendering for various notification types.
 * All templates generate HTML emails with consistent styling.
 */
interface EmailTemplateServiceInterface
{
    /**
     * Render crew registration notification email
     *
     * @param User $user User entity
     * @param array $profile Crew profile data containing:
     *   - firstName: string
     *   - lastName: string
     *   - displayName: string|null
     *   - mobile: string|null
     *   - skill: int
     *   - membershipNumber: string|null
     *   - partnerKey: string|null
     *   - socialPreference: mixed
     *   - experience: string|null
     * @return string HTML email body
     */
    public function renderCrewRegistrationNotification(User $user, array $profile): string;

    /**
     * Render boat owner registration notification email
     *
     * @param User $user User entity
     * @param array $profile Boat profile data containing:
     *   - displayName: string|null
     *   - ownerFirstName: string
     *   - ownerLastName: string
     *   - ownerMobile: string|null
     *   - minBerths: int
     *   - maxBerths: int
     *   - assistanceRequired: mixed
     *   - socialPreference: mixed
     * @return string HTML email body
     */
    public function renderBoatOwnerRegistrationNotification(User $user, array $profile): string;

    /**
     * Render crew reminder notification email (sent ~24h before event)
     *
     * @param string $firstName Crew member's first name
     * @param string $eventId Event identifier (e.g. "Fri May 29")
     * @param string $eventDate Event date (YYYY-MM-DD)
     * @param string $startTime Event start time (HH:MM:SS)
     * @return string HTML email body
     */
    public function renderCrewReminderNotification(
        string $firstName,
        string $eventId,
        string $eventDate,
        string $startTime
    ): string;

    /**
     * Render crew list notification email (sent to admin + boat owners at blackout start)
     *
     * @param string $eventId Event identifier (e.g. "Fri May 29")
     * @param string $eventDate Event date (YYYY-MM-DD)
     * @param array $crewedBoats Array of crewed boat data from flotilla['crewed_boats']
     * @return string HTML email body
     */
    public function renderCrewListNotification(
        string $eventId,
        string $eventDate,
        array $crewedBoats
    ): string;
}
