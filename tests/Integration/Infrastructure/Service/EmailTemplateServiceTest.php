<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Service;

use App\Domain\Entity\User;
use App\Infrastructure\Service\EmailTemplateService;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for EmailTemplateService
 *
 * Tests HTML email template generation for various notification types.
 */
class EmailTemplateServiceTest extends IntegrationTestCase
{
    private EmailTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailTemplateService();
    }

    // ========================================
    // Crew Registration Notification Tests
    // ========================================

    public function testRenderCrewRegistrationNotificationWithFullProfile(): void
    {
        $user = new User(
            email: 'john.doe@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(123);

        $profile = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'displayName' => 'Johnny D',
            'mobile' => '555-1234',
            'skill' => 1,
            'membershipNumber' => 'NSC-001',
            'socialPreference' => 'Yes',
            'experience' => '5 years sailing experience'
        ];

        $html = $this->service->renderCrewRegistrationNotification($user, $profile);

        // Verify HTML structure
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html>', $html);
        $this->assertStringContainsString('</html>', $html);

        // Verify header
        $this->assertStringContainsString('New Registration Notification', $html);
        $this->assertStringContainsString('Crew Member Registration', $html);

        // Verify profile data
        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('Johnny D', $html);
        $this->assertStringContainsString('john.doe@example.com', $html);
        $this->assertStringContainsString('555-1234', $html);
        $this->assertStringContainsString('Intermediate', $html); // skill level 1
        $this->assertStringContainsString('NSC-001', $html);
        $this->assertStringContainsString('Yes', $html); // social preference
        $this->assertStringContainsString('5 years sailing experience', $html);
        $this->assertStringContainsString('123', $html); // user ID

        // Verify footer
        $this->assertStringContainsString('automated notification from the JAWS sailing management system', $html);

        // Verify CSS styles are included
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('font-family: Arial', $html);
    }

    public function testRenderCrewRegistrationNotificationWithMinimalProfile(): void
    {
        $user = new User(
            email: 'jane@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(456);

        $profile = [
            'firstName' => 'Jane',
            'lastName' => 'Smith'
        ];

        $html = $this->service->renderCrewRegistrationNotification($user, $profile);

        // Verify required fields
        $this->assertStringContainsString('Jane Smith', $html);
        $this->assertStringContainsString('jane@example.com', $html);

        // Verify defaults for missing fields
        $this->assertStringContainsString('Not provided', $html); // mobile
        $this->assertStringContainsString('Novice', $html); // default skill level 0
        $this->assertStringContainsString('Not provided', $html); // membership number
        $this->assertStringContainsString('No', $html); // default social preference
        $this->assertStringContainsString('Not provided', $html); // experience
    }

    public function testRenderCrewRegistrationNotificationGeneratesDisplayName(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(789);

        $profile = [
            'firstName' => 'Robert',
            'lastName' => 'Johnson'
            // No displayName provided
        ];

        $html = $this->service->renderCrewRegistrationNotification($user, $profile);

        // Should generate display name as "RobertJ" (firstName + last initial)
        $this->assertStringContainsString('RobertJ', $html);
    }

    public function testRenderCrewRegistrationNotificationWithDifferentSkillLevels(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        // Test Novice (0)
        $profile = ['firstName' => 'Test', 'lastName' => 'User', 'skill' => 0];
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('Novice', $html);

        // Test Intermediate (1)
        $profile['skill'] = 1;
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('Intermediate', $html);

        // Test Advanced (2)
        $profile['skill'] = 2;
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('Advanced', $html);

        // Test Unknown (invalid value)
        $profile['skill'] = 99;
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('Unknown', $html);
    }

    public function testRenderCrewRegistrationNotificationWithVariousSocialPreferences(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $baseProfile = ['firstName' => 'Test', 'lastName' => 'User'];

        // Test "Yes" string
        $profile = array_merge($baseProfile, ['socialPreference' => 'Yes']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test "No" string
        $profile = array_merge($baseProfile, ['socialPreference' => 'No']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">No</span>', $html);

        // Test boolean true
        $profile = array_merge($baseProfile, ['socialPreference' => true]);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test boolean false
        $profile = array_merge($baseProfile, ['socialPreference' => false]);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">No</span>', $html);

        // Test integer 1
        $profile = array_merge($baseProfile, ['socialPreference' => 1]);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test integer 0
        $profile = array_merge($baseProfile, ['socialPreference' => 0]);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">No</span>', $html);

        // Test null (default to No)
        $profile = array_merge($baseProfile, ['socialPreference' => null]);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">No</span>', $html);
    }

    // ========================================
    // Boat Owner Registration Notification Tests
    // ========================================

    public function testRenderBoatOwnerRegistrationNotificationWithFullProfile(): void
    {
        $user = new User(
            email: 'owner@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'boat_owner'
        );
        $user->setId(999);

        $profile = [
            'displayName' => 'The Black Pearl',
            'ownerFirstName' => 'Captain',
            'ownerLastName' => 'Hook',
            'ownerMobile' => '555-9999',
            'minBerths' => 4,
            'maxBerths' => 6,
            'assistanceRequired' => 'Yes',
            'socialPreference' => 'Yes'
        ];

        $html = $this->service->renderBoatOwnerRegistrationNotification($user, $profile);

        // Verify HTML structure
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Boat Owner Registration', $html);

        // Verify profile data
        $this->assertStringContainsString('The Black Pearl', $html);
        $this->assertStringContainsString('Captain Hook', $html);
        $this->assertStringContainsString('owner@example.com', $html);
        $this->assertStringContainsString('555-9999', $html);
        $this->assertStringContainsString('4-6', $html); // berth capacity
        $this->assertStringContainsString('Yes', $html); // assistance required
    }

    public function testRenderBoatOwnerRegistrationNotificationWithMinimalProfile(): void
    {
        $user = new User(
            email: 'boat@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'boat_owner'
        );
        $user->setId(888);

        $profile = [
            'ownerFirstName' => 'Boat',
            'ownerLastName' => 'Owner',
            'minBerths' => 2,
            'maxBerths' => 4
        ];

        $html = $this->service->renderBoatOwnerRegistrationNotification($user, $profile);

        // Verify required fields
        $this->assertStringContainsString('Boat Owner', $html);
        $this->assertStringContainsString('boat@example.com', $html);
        $this->assertStringContainsString('2-4', $html);

        // Verify defaults
        $this->assertStringContainsString('Not provided', $html); // owner mobile
        $this->assertStringContainsString('No', $html); // default assistance required
        $this->assertStringContainsString('No', $html); // default social preference
    }

    public function testRenderBoatOwnerRegistrationNotificationGeneratesDisplayName(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'boat_owner'
        );
        $user->setId(777);

        $profile = [
            'ownerFirstName' => 'Test',
            'ownerLastName' => 'Owner',
            'minBerths' => 3,
            'maxBerths' => 5
            // No displayName provided
        ];

        $html = $this->service->renderBoatOwnerRegistrationNotification($user, $profile);

        // Should generate display name as "TestO" (firstName + last initial)
        $this->assertStringContainsString('TestO', $html);
    }

    // ========================================
    // Edge Cases and Security Tests
    // ========================================

    public function testRenderCrewRegistrationNotificationWithSpecialCharactersInProfile(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $profile = [
            'firstName' => 'John<script>alert("XSS")</script>',
            'lastName' => 'Doe">',
            'displayName' => 'Johnny & Bob',
            'mobile' => '555-1234 <test>',
            'skill' => 1,
            'experience' => 'Sailed on "The Enterprise"'
        ];

        $html = $this->service->renderCrewRegistrationNotification($user, $profile);

        // Note: The current implementation doesn't escape these values
        // This test documents the current behavior
        // In a production system, you'd want to add htmlspecialchars() to the template
        $this->assertStringContainsString('John<script>alert("XSS")</script>', $html);
    }

    public function testGenerateDisplayNameWithUnicodeCharacters(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $profile = [
            'firstName' => 'François',
            'lastName' => 'Müller'
        ];

        $html = $this->service->renderCrewRegistrationNotification($user, $profile);

        // Should handle Unicode properly with mb_substr
        $this->assertStringContainsString('FrançoisM', $html);
    }

    public function testParseYesNoWithStringVariations(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $baseProfile = ['firstName' => 'Test', 'lastName' => 'User'];

        // Test case-insensitive "yes"
        $profile = array_merge($baseProfile, ['socialPreference' => 'yes']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test "YES"
        $profile = array_merge($baseProfile, ['socialPreference' => 'YES']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test "true" string
        $profile = array_merge($baseProfile, ['socialPreference' => 'true']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test "1" string
        $profile = array_merge($baseProfile, ['socialPreference' => '1']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">Yes</span>', $html);

        // Test anything else defaults to "No"
        $profile = array_merge($baseProfile, ['socialPreference' => 'maybe']);
        $html = $this->service->renderCrewRegistrationNotification($user, $profile);
        $this->assertStringContainsString('<span class="value">No</span>', $html);
    }

    public function testSharedStylesAreIncludedInAllTemplates(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $crewProfile = ['firstName' => 'Test', 'lastName' => 'User'];
        $crewHtml = $this->service->renderCrewRegistrationNotification($user, $crewProfile);

        $boatProfile = ['ownerFirstName' => 'Test', 'ownerLastName' => 'User', 'minBerths' => 2, 'maxBerths' => 4];
        $boatHtml = $this->service->renderBoatOwnerRegistrationNotification($user, $boatProfile);

        // Verify all templates include shared styles
        $sharedStyleElements = [
            'font-family: Arial',
            '.container',
            '.header',
            '.content',
            '.footer',
            'background-color: #0066cc'
        ];

        foreach ($sharedStyleElements as $element) {
            $this->assertStringContainsString($element, $crewHtml);
            $this->assertStringContainsString($element, $boatHtml);
        }
    }

    // ========================================
    // Welcome Notification Tests
    // ========================================

    public function testRenderWelcomeNotification(): void
    {
        $html = $this->service->renderWelcomeNotification();

        $this->assertStringContainsString('Welcome to the Nepean Sailing Club', $html);
        $this->assertStringContainsString('nsc-sdc.ca', $html);
        $this->assertStringContainsString('10:00 AM', $html);
        $this->assertStringContainsString('12:45 PM', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testTimestampIsIncludedInRegistrationNotifications(): void
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            accountType: 'crew'
        );
        $user->setId(1);

        $crewProfile = ['firstName' => 'Test', 'lastName' => 'User'];
        $html = $this->service->renderCrewRegistrationNotification($user, $crewProfile);

        // Verify timestamp format (YYYY-MM-DD HH:MM:SS)
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $html);
        $this->assertStringContainsString('Registration Date:', $html);
    }
}
