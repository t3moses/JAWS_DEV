<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Domain\Entity\User;

/**
 * Email Template Service
 *
 * Provides HTML email template rendering for various notification types.
 * Centralizes all email template generation with consistent styling.
 */
class EmailTemplateService implements EmailTemplateServiceInterface
{
    /**
     * Render crew registration notification email
     *
     * @param User $user User entity
     * @param array $profile Crew profile data
     * @return string HTML email body
     */
    public function renderCrewRegistrationNotification(User $user, array $profile): string
    {
        $displayName = $profile['displayName'] ?? $this->generateDisplayName(
            $profile['firstName'],
            $profile['lastName']
        );

        $skillLabel = $this->getSkillLevelLabel($profile['skill'] ?? 0);
        $mobile = $profile['mobile'] ?? 'Not provided';
        $membershipNumber = $profile['membershipNumber'] ?? 'Not provided';
        $socialPreference = $this->parseYesNo($profile['socialPreference'] ?? null) ? 'Yes' : 'No';
        $experience = $profile['experience'] ?? 'Not provided';
        $timestamp = date('Y-m-d H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        {$this->getSharedStyles()}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>New Registration Notification</h2>
        </div>
        <div class="content">
            <div class="section">
                <h3>Crew Member Registration</h3>
                <div class="field">
                    <span class="label">Name:</span>
                    <span class="value">{$profile['firstName']} {$profile['lastName']}</span>
                </div>
                <div class="field">
                    <span class="label">Display Name:</span>
                    <span class="value">{$displayName}</span>
                </div>
                <div class="field">
                    <span class="label">Email:</span>
                    <span class="value">{$user->getEmail()}</span>
                </div>
                <div class="field">
                    <span class="label">Mobile:</span>
                    <span class="value">{$mobile}</span>
                </div>
                <div class="field">
                    <span class="label">Skill Level:</span>
                    <span class="value">{$skillLabel}</span>
                </div>
                <div class="field">
                    <span class="label">Membership Number:</span>
                    <span class="value">{$membershipNumber}</span>
                </div>
                <div class="field">
                    <span class="label">Social Preference:</span>
                    <span class="value">{$socialPreference}</span>
                </div>
                <div class="field">
                    <span class="label">Experience:</span>
                    <span class="value">{$experience}</span>
                </div>
                <div class="field">
                    <span class="label">User ID:</span>
                    <span class="value">{$user->getId()}</span>
                </div>
                <div class="field">
                    <span class="label">Registration Date:</span>
                    <span class="value">{$timestamp}</span>
                </div>
            </div>
            <div class="section">
                <h3>AWS SES Email Verification</h3>
                <p>To enable this user to receive system emails, verify their email address in AWS SES by running the following command:</p>
                <div class="command-block">
                    aws ses verify-email-identity --email-address {$user->getEmail()}
                </div>
                <p style="font-size: 0.9em; color: #666;">Copy and paste this command in your AWS CLI terminal.</p>
            </div>
            <div class="footer">
                <p>This is an automated notification from the JAWS sailing management system.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render boat owner registration notification email
     *
     * @param User $user User entity
     * @param array $profile Boat profile data
     * @return string HTML email body
     */
    public function renderBoatOwnerRegistrationNotification(User $user, array $profile): string
    {
        $displayName = $profile['displayName'] ?? $this->generateDisplayName(
            $profile['ownerFirstName'],
            $profile['ownerLastName']
        );

        $ownerMobile = $profile['ownerMobile'] ?? 'Not provided';
        $assistanceRequired = $this->parseYesNo($profile['assistanceRequired'] ?? null) ? 'Yes' : 'No';
        $socialPreference = $this->parseYesNo($profile['socialPreference'] ?? null) ? 'Yes' : 'No';
        $timestamp = date('Y-m-d H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        {$this->getSharedStyles()}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>New Registration Notification</h2>
        </div>
        <div class="content">
            <div class="section">
                <h3>Boat Owner Registration</h3>
                <div class="field">
                    <span class="label">Boat Name:</span>
                    <span class="value">{$displayName}</span>
                </div>
                <div class="field">
                    <span class="label">Owner:</span>
                    <span class="value">{$profile['ownerFirstName']} {$profile['ownerLastName']}</span>
                </div>
                <div class="field">
                    <span class="label">Owner Email:</span>
                    <span class="value">{$user->getEmail()}</span>
                </div>
                <div class="field">
                    <span class="label">Owner Mobile:</span>
                    <span class="value">{$ownerMobile}</span>
                </div>
                <div class="field">
                    <span class="label">Berth Capacity:</span>
                    <span class="value">{$profile['minBerths']}-{$profile['maxBerths']}</span>
                </div>
                <div class="field">
                    <span class="label">Assistance Required:</span>
                    <span class="value">{$assistanceRequired}</span>
                </div>
                <div class="field">
                    <span class="label">Social Preference:</span>
                    <span class="value">{$socialPreference}</span>
                </div>
                <div class="field">
                    <span class="label">User ID:</span>
                    <span class="value">{$user->getId()}</span>
                </div>
                <div class="field">
                    <span class="label">Registration Date:</span>
                    <span class="value">{$timestamp}</span>
                </div>
            </div>
            <div class="section">
                <h3>AWS SES Email Verification</h3>
                <p>To enable this user to receive system emails, verify their email address in AWS SES by running the following command:</p>
                <div class="command-block">
                    aws ses verify-email-identity --email-address {$user->getEmail()}
                </div>
                <p style="font-size: 0.9em; color: #666;">Copy and paste this command in your AWS CLI terminal.</p>
            </div>
            <div class="footer">
                <p>This is an automated notification from the JAWS sailing management system.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

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
    ): string {
        $friendlyDate = date('l, F j, Y', strtotime($eventDate));
        $friendlyTime = date('g:i a', strtotime($startTime));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        {$this->getSharedStyles()}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Social Day Cruising - Reminder</h2>
        </div>
        <div class="content">
            <p>Hi {$firstName},</p>

            <p>This is a friendly reminder that you are registered for tomorrow's sailing event:</p>

            <div class="section">
                <div class="field">
                    <span class="label">Event:</span>
                    <span class="value">{$eventId}</span>
                </div>
                <div class="field">
                    <span class="label">Date:</span>
                    <span class="value">{$friendlyDate}</span>
                </div>
                <div class="field">
                    <span class="label">Start Time:</span>
                    <span class="value">{$friendlyTime}</span>
                </div>
            </div>

            <p>Please ensure you are ready at the dock by start time. If your plans have changed, update your availability as soon as possible.</p>

            <div class="footer">
                <p>This is an automated notification from the JAWS sailing management system.</p>
                <p>If you have any questions, please contact the sailing coordinator.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

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
    ): string {
        $friendlyDate = date('l, F j, Y', strtotime($eventDate));
        $boatSections = '';

        foreach ($crewedBoats as $crewedBoat) {
            $boat = $crewedBoat['boat'];
            $crews = $crewedBoat['crews'];
            $boatName = htmlspecialchars($boat['display_name']);

            $crewRows = '';
            foreach ($crews as $crew) {
                $name = htmlspecialchars($crew['first_name'] . ' ' . $crew['last_name']);
                $skill = $this->getSkillLevelLabel($crew['skill'] ?? 0);
                $experience = htmlspecialchars($crew['experience'] ?? 'Not provided');
                $crewRows .= <<<ROW
                    <tr>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #eee;">{$name}</td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #eee;">{$skill}</td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #eee;">{$experience}</td>
                    </tr>
ROW;
            }

            $boatSections .= <<<SECTION
            <div class="section">
                <h3>{$boatName}</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #e8f0fb;">
                            <th style="padding: 6px 10px; text-align: left;">Name</th>
                            <th style="padding: 6px 10px; text-align: left;">Skill</th>
                            <th style="padding: 6px 10px; text-align: left;">Experience</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$crewRows}
                    </tbody>
                </table>
            </div>
SECTION;
        }

        $boatCount = count($crewedBoats);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        {$this->getSharedStyles()}
        table { font-size: 0.95em; }
        th { color: #0066cc; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Social Day Cruising - Crew List</h2>
        </div>
        <div class="content">
            <p><strong>Event:</strong> {$eventId} &mdash; {$friendlyDate}</p>
            <p><strong>Boats sailing today:</strong> {$boatCount}</p>

            {$boatSections}

            <div class="footer">
                <p>This is an automated notification from the JAWS sailing management system.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get shared CSS styles used across all email templates
     *
     * @return string CSS styles
     */
    private function getSharedStyles(): string
    {
        return <<<CSS
body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
.container { max-width: 600px; margin: 0 auto; padding: 20px; }
.header { background-color: #0066cc; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
.content { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
.section { background-color: white; padding: 15px; border-radius: 5px; margin-top: 15px; }
.field { margin-bottom: 10px; }
.label { font-weight: bold; color: #0066cc; display: inline-block; min-width: 180px; }
.value { display: inline; }
.footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
.command-block { background-color: #f4f4f4; padding: 10px; border-left: 4px solid #0066cc; font-family: 'Courier New', monospace; margin: 10px 0; overflow-x: auto; }
CSS;
    }

    /**
     * Get human-readable skill level label
     *
     * @param int $skillLevel Skill level (0-2)
     * @return string Skill level label
     */
    private function getSkillLevelLabel(int $skillLevel): string
    {
        return match($skillLevel) {
            0 => 'Novice',
            1 => 'Intermediate',
            2 => 'Advanced',
            default => 'Unknown'
        };
    }

    /**
     * Generate display name from first name and last initial
     *
     * @param string $firstName First name
     * @param string $lastName Last name
     * @return string Display name in format "FirstName L."
     */
    private function generateDisplayName(string $firstName, string $lastName): string
    {
        $lastInitial = mb_substr($lastName, 0, 1);
        return trim($firstName) . $lastInitial;
    }

    /**
     * Parse Yes/No value to boolean
     *
     * @param mixed $value Value to parse
     * @return bool Parsed boolean value
     */
    private function parseYesNo(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return strcasecmp($value, 'Yes') === 0
                || strcasecmp($value, 'true') === 0
                || $value === '1';
        }

        return false;
    }
}
