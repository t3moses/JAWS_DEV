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
     * Render assignment notification email
     *
     * @param string $recipientFirstName Recipient's first name
     * @param string $eventId Event identifier
     * @param string $boatName Boat name
     * @param array $crews Array of crew data
     * @return string HTML email body
     */
    public function renderAssignmentNotification(
        string $recipientFirstName,
        string $eventId,
        string $boatName,
        array $crews
    ): string {
        $crewList = '';
        foreach ($crews as $crew) {
            $crewList .= sprintf(
                '<li>%s %s - Skill: %s</li>',
                htmlspecialchars($crew['first_name']),
                htmlspecialchars($crew['last_name']),
                $this->getSkillLevelLabel($crew['skill'])
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        {$this->getSharedStyles()}
        .boat-name { font-size: 1.2em; font-weight: bold; color: #0066cc; }
        .crew-list { background-color: white; padding: 15px; border-radius: 5px; margin-top: 15px; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Social Day Cruising - Assignment Notification</h2>
        </div>
        <div class="content">
            <p>Hi {$recipientFirstName},</p>

            <p>You have been assigned for the upcoming sailing event:</p>

            <p><strong>Event:</strong> {$eventId}</p>
            <p><strong>Boat:</strong> <span class="boat-name">{$boatName}</span></p>

            <div class="crew-list">
                <h3>Crew Members:</h3>
                <ul>
                    {$crewList}
                </ul>
            </div>

            <p>Please confirm your participation and coordinate with your crew members.</p>

            <div class="footer">
                <p>This is an automated notification from the JAWS (Just Another Web System) sailing management system.</p>
                <p>If you have any questions, please contact the sailing coordinator.</p>
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
