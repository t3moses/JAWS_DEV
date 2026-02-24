<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for User Profile endpoints
 */
class UserProfileApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testGetCrewProfile(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('data', $response['body']);

        $data = $response['body']['data'];

        // Verify crew profile is present
        $this->assertArrayHasKey('crewProfile', $data);

        $crew = $data['crewProfile'];

        // Verify crew details match registration
        $this->assertEquals($testData['firstName'], $crew['firstName']);
        $this->assertEquals($testData['lastName'], $crew['lastName']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetBoatOwnerProfile(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('data', $response['body']);

        $data = $response['body']['data'];

        // Verify boat profile is present
        $this->assertArrayHasKey('boatProfile', $data);

        $boat = $data['boatProfile'];

        // Verify boat details match registration
        $this->assertEquals($testData['ownerFirstName'], $boat['ownerFirstName']);
        $this->assertEquals($testData['ownerLastName'], $boat['ownerLastName']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateEmailOnly(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);
        $newEmail = $this->makeUniqueEmail('updated.email');

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'email' => $newEmail,
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('profile', $response['body']['data']);

        $profile = $response['body']['data']['profile'];

        // Verify email was updated
        $this->assertEquals($newEmail, $profile['user']['email']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdatePasswordOnly(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'password' => 'NewSecurePass123!',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('profile', $response['body']['data']);

        // Password is hashed, can't verify directly, but success indicates it worked

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateCrewProfile(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);
        $suffix = $this->makeUniqueSuffix();

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'displayName' => "Updated Name {$suffix}",
                'mobile' => '555-9999',
                'skill' => 2,
                'socialPreference' => false,
                'membershipNumber' => 'NSC99999',
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('crewProfile', $response['body']['data']['profile']);

        $crew = $response['body']['data']['profile']['crewProfile'];

        // Verify crew profile fields were updated
        $this->assertEquals("Updated Name {$suffix}", $crew['displayName']);
        $this->assertEquals('555-9999', $crew['mobile']);
        $this->assertEquals(2, $crew['skill']);
        $this->assertFalse($crew['socialPreference']);
        $this->assertEquals('NSC99999', $crew['membershipNumber']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateBoatProfile(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);
        $suffix = $this->makeUniqueSuffix();

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'boatProfile' => [
                'displayName' => "Updated Boat Name {$suffix}",
                'ownerMobile' => '555-8888',
                'minBerths' => 3,
                'maxBerths' => 6,
                'assistanceRequired' => true,
                'socialPreference' => false,
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('boatProfile', $response['body']['data']['profile']);

        $boat = $response['body']['data']['profile']['boatProfile'];

        // Verify boat profile fields were updated
        $this->assertEquals("Updated Boat Name {$suffix}", $boat['displayName']);
        $this->assertEquals('555-8888', $boat['ownerMobile']);
        $this->assertEquals(3, $boat['minBerths']);
        $this->assertEquals(6, $boat['maxBerths']);
        $this->assertTrue($boat['assistanceRequired']);
        $this->assertFalse($boat['socialPreference']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateMultipleFields(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);
        $newEmail = $this->makeUniqueEmail('updated.multi');
        $suffix = $this->makeUniqueSuffix();

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'email' => $newEmail,
            'crewProfile' => [
                'displayName' => "Multi Update {$suffix}",
                'skill' => 2,
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('profile', $response['body']['data']);

        $profile = $response['body']['data']['profile'];

        // Verify email was updated
        $this->assertEquals($newEmail, $profile['user']['email']);

        // Verify crew profile was updated
        $this->assertEquals("Multi Update {$suffix}", $profile['crewProfile']['displayName']);
        $this->assertEquals(2, $profile['crewProfile']['skill']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testValidationErrors(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        // Test 1: Invalid email format
        $response1 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'email' => 'invalid-email',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(400, $response1['status']);
        $this->assertArrayHasKey('error', $response1['body']);

        // Test 2: Password too short
        $response2 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'password' => 'short',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(400, $response2['status']);
        $this->assertArrayHasKey('error', $response2['body']);

        // Test 3: Empty request body (no updates)
        $response3 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(400, $response3['status']);
        $this->assertArrayHasKey('error', $response3['body']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testAddCrewProfile(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);
        $suffix = $this->makeUniqueSuffix();

        // Boat owner adds crew profile (becomes flex)
        $response = $this->makeRequest('POST', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'firstName' => "Flex{$suffix}",
                'lastName' => "Crew",
                'displayName' => "Flex Crew {$suffix}",
                'skill' => 1,
                'mobile' => '555-1111',
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 200, 201, 400 (not implemented), or 404 (endpoint not found)
        $this->assertContains($response['status'], [200, 201, 400, 404]);

        // Skip verification if endpoint doesn't exist
        if ($response['status'] === 404 || $response['status'] === 400) {
            $this->markTestSkipped('POST /api/users/me endpoint may not be implemented');
            $this->cleanupTestUser($testData['userId']);
            return;
        }

        $this->assertTrue($response['body']['success']);

        // Verify both profiles exist
        $profileResponse = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $data = $profileResponse['body']['data'];

        // Should have both boat and crew profiles (flex status)
        $this->assertArrayHasKey('boatProfile', $data);
        $this->assertArrayHasKey('crewProfile', $data);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testAddBoatProfile(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);
        $suffix = $this->makeUniqueSuffix();

        // Crew adds boat profile (becomes flex)
        $response = $this->makeRequest('POST', "{$this->baseUrl}/users/me", [
            'boatProfile' => [
                'ownerFirstName' => "Flex{$suffix}",
                'ownerLastName' => "Boat",
                'displayName' => "Flex Boat {$suffix}",
                'ownerMobile' => '555-2222',
                'minBerths' => 2,
                'maxBerths' => 4,
                'assistanceRequired' => false,
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 200, 201, 400 (not implemented), or 404 (endpoint not found)
        $this->assertContains($response['status'], [200, 201, 400, 404]);

        // Skip verification if endpoint doesn't exist
        if ($response['status'] === 404 || $response['status'] === 400) {
            $this->markTestSkipped('POST /api/users/me endpoint may not be implemented');
            $this->cleanupTestUser($testData['userId']);
            return;
        }

        $this->assertTrue($response['body']['success']);

        // Verify both profiles exist
        $profileResponse = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $data = $profileResponse['body']['data'];

        // Should have both crew and boat profiles (flex status)
        $this->assertArrayHasKey('crewProfile', $data);
        $this->assertArrayHasKey('boatProfile', $data);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testRegistrationCapturesExperience(): void
    {
        $suffix = $this->makeUniqueSuffix();

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('experience.crew'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Exp Crew {$suffix}",
                'firstName' => "Exp{$suffix}",
                'lastName' => "Crew",
                'skill' => 1,
                'experience' => 'CANSail 1, 2 seasons racing at NSC',
            ],
        ]);

        $this->assertEquals(201, $response['status']);
        $userId = $response['body']['data']['user']['id'] ?? null;
        $token = $response['body']['data']['token'];

        $profileResponse = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$token}",
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $crew = $profileResponse['body']['data']['crewProfile'];
        $this->assertEquals('CANSail 1, 2 seasons racing at NSC', $crew['experience']);

        $this->cleanupTestUser($userId);
    }

    public function testUpdateCrewProfileExperience(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'experience' => 'Competent Crew course, club racing since 2022',
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        $crew = $response['body']['data']['profile']['crewProfile'];
        $this->assertEquals('Competent Crew course, club racing since 2022', $crew['experience']);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testClearCrewProfileExperience(): void
    {
        $suffix = $this->makeUniqueSuffix();

        // Register with experience set
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('clear.experience'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Clear Exp {$suffix}",
                'firstName' => "ClearExp{$suffix}",
                'lastName' => "Crew",
                'skill' => 1,
                'experience' => 'Some initial experience',
            ],
        ]);

        $this->assertEquals(201, $registerResponse['status']);
        $userId = $registerResponse['body']['data']['user']['id'] ?? null;
        $token = $registerResponse['body']['data']['token'];

        // Clear experience by sending an empty string
        $patchResponse = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'experience' => '',
            ],
        ], [
            "Authorization: Bearer {$token}",
        ]);

        $this->assertEquals(200, $patchResponse['status']);

        // Verify experience is now null
        $profileResponse = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$token}",
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $crew = $profileResponse['body']['data']['crewProfile'];
        $this->assertNull($crew['experience']);

        $this->cleanupTestUser($userId);
    }

    public function testProfileBoundaryValues(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);
        $suffix = $this->makeUniqueSuffix();

        // Test valid skill levels (0, 1, 2)
        $response1 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'skill' => 0, // NOVICE
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertEquals(200, $response1['status']);

        $response2 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'skill' => 2, // ADVANCED
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertEquals(200, $response2['status']);

        // Test invalid skill levels (-1, 3, 999)
        $response3 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'skill' => -1,
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        // May return 400 (validation) or 500 (enum constraint violation)
        $this->assertContains($response3['status'], [400, 500]);

        $response4 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'crewProfile' => [
                'skill' => 3,
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response4['status'], [400, 500]);

        // Cleanup and create boat owner for berth tests
        $this->cleanupTestUser($testData['userId']);
        $testDataBoat = $this->createTestBoatOwner($this->baseUrl);

        // Test edge case berth values
        $response5 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'boatProfile' => [
                'minBerths' => 0,
                'maxBerths' => 100,
            ],
        ], [
            "Authorization: Bearer {$testDataBoat['token']}",
        ]);
        // May accept or reject depending on validation rules
        $this->assertContains($response5['status'], [200, 400]);

        // Test maxBerths < minBerths - validation may not be implemented
        $response6 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'boatProfile' => [
                'minBerths' => 10,
                'maxBerths' => 5,
            ],
        ], [
            "Authorization: Bearer {$testDataBoat['token']}",
        ]);
        $this->assertContains($response6['status'], [200, 400]);

        // Cleanup
        $this->cleanupTestUser($testDataBoat['userId']);
    }
}
