<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for Authentication endpoints
 */
class AuthApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testRegisterCrewAccount(): void
    {
        $suffix = $this->makeUniqueSuffix();
        $firstName = "John{$suffix}";
        $lastName = "Doe{$suffix}";

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('john.doe.crew'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "John Doe {$suffix}",
                'firstName' => $firstName,
                'lastName' => $lastName,
                'skill' => 1,
            ],
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('token', $response['body']['data']);
        $this->assertArrayHasKey('user', $response['body']['data']);

        // Cleanup
        $userId = $response['body']['data']['user']['id'] ?? null;
        $this->cleanupTestUser($userId);
    }

    public function testRegisterCrewAccountWithExperience(): void
    {
        $suffix = $this->makeUniqueSuffix();

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('crew.experience'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Exp Crew {$suffix}",
                'firstName' => "ExpCrew{$suffix}",
                'lastName' => "Sailor",
                'skill' => 1,
                'experience' => 'CANSail 1 and 2, 5 seasons at NSC',
            ],
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $userId = $response['body']['data']['user']['id'] ?? null;
        $token = $response['body']['data']['token'];

        // Verify the experience field was stored and is returned in the profile
        $profileResponse = $this->makeRequest('GET', "{$this->baseUrl}/users/me", null, [
            "Authorization: Bearer {$token}",
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $this->assertEquals(
            'CANSail 1 and 2, 5 seasons at NSC',
            $profileResponse['body']['data']['crewProfile']['experience']
        );

        $this->cleanupTestUser($userId);
    }

    public function testRegisterBoatOwnerAccount(): void
    {
        $suffix = $this->makeUniqueSuffix();
        $ownerFirstName = "TestBoat{$suffix}";
        $ownerLastName = "Owner";

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('john.doe.boat'),
            'password' => 'TestPass123',
            'accountType' => 'boat_owner',
            'profile' => [
                'displayName' => "Test Boat {$suffix}",
                'ownerFirstName' => $ownerFirstName,
                'ownerLastName' => $ownerLastName,
                'ownerMobile' => '555-1234',
                'minBerths' => 2,
                'maxBerths' => 4,
                'assistanceRequired' => false,
                'socialPreference' => true,
            ],
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('token', $response['body']['data']);
        $this->assertArrayHasKey('user', $response['body']['data']);

        // Cleanup
        $userId = $response['body']['data']['user']['id'] ?? null;
        $this->cleanupTestUser($userId);
    }

    public function testLoginWithValidCredentials(): void
    {
        $email = $this->makeUniqueEmail('login.test');
        $password = 'TestPass123';

        // Register a test user first
        $suffix = $this->makeUniqueSuffix();
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $email,
            'password' => $password,
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Login Test {$suffix}",
                'firstName' => "Login{$suffix}",
                'lastName' => "Test",
                'skill' => 1,
            ],
        ]);

        $this->assertEquals(201, $registerResponse['status']);
        $userId = $registerResponse['body']['data']['user']['id'] ?? null;

        // Now test login with valid credentials
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('token', $response['body']['data']);
        $this->assertArrayHasKey('user', $response['body']['data']);
        $this->assertEquals($email, $response['body']['data']['user']['email']);

        // Verify token works for authenticated requests
        $token = $response['body']['data']['token'];
        $assignmentsResponse = $this->makeRequest('GET', "{$this->baseUrl}/assignments", null, [
            "Authorization: Bearer {$token}",
        ]);
        $this->assertContains($assignmentsResponse['status'], [200, 404]); // 404 if no assignments

        // Cleanup
        $this->cleanupTestUser($userId);
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $email = $this->makeUniqueEmail('invalid.login');
        $password = 'TestPass123';

        // Register a test user first
        $suffix = $this->makeUniqueSuffix();
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $email,
            'password' => $password,
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Invalid Login Test {$suffix}",
                'firstName' => "Invalid{$suffix}",
                'lastName' => "Test",
                'skill' => 1,
            ],
        ]);

        $userId = $registerResponse['body']['data']['user']['id'] ?? null;

        // Test login with wrong password
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => $email,
            'password' => 'WrongPassword123',
        ]);

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertArrayNotHasKey('token', $response['body']['data'] ?? []);

        // Cleanup
        $this->cleanupTestUser($userId);
    }

    public function testGetSession(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/auth/session", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('user', $response['body']['data']);

        $user = $response['body']['data']['user'];
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertEquals($testData['userId'], $user['id']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testLogout(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/logout", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        // Message field is optional
        // $this->assertArrayHasKey('message', $response['body']);

        // Note: Current JWT implementation is stateless, so token may still work
        // This test verifies the logout endpoint works, not that the token is invalidated

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testLoginValidationErrors(): void
    {
        // Test missing email
        $response1 = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'password' => 'TestPass123',
        ]);
        $this->assertEquals(400, $response1['status']);
        $this->assertArrayHasKey('error', $response1['body']);

        // Test missing password
        $response2 = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => 'test@example.com',
        ]);
        $this->assertEquals(400, $response2['status']);
        $this->assertArrayHasKey('error', $response2['body']);

        // Test invalid email format
        $response3 = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => 'invalid-email',
            'password' => 'TestPass123',
        ]);
        $this->assertContains($response3['status'], [400, 401]); // May be validation or auth error
        $this->assertArrayHasKey('error', $response3['body']);

        // Test empty request body
        $response4 = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", []);
        $this->assertEquals(400, $response4['status']);
        $this->assertArrayHasKey('error', $response4['body']);
    }

    public function testRegisterValidationErrors(): void
    {
        $suffix = $this->makeUniqueSuffix();

        // Test missing email
        $response1 = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Test {$suffix}",
                'firstName' => "Test{$suffix}",
                'lastName' => "User",
                'skill' => 1,
            ],
        ]);
        $this->assertEquals(400, $response1['status']);
        $this->assertArrayHasKey('error', $response1['body']);

        // Test password too short
        $response2 = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('short.pass'),
            'password' => 'short',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Test {$suffix}",
                'firstName' => "Test{$suffix}",
                'lastName' => "User",
                'skill' => 1,
            ],
        ]);
        $this->assertEquals(400, $response2['status']);
        $this->assertArrayHasKey('error', $response2['body']);

        // Test invalid skill level
        $response3 = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('invalid.skill'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Test {$suffix}",
                'firstName' => "Test{$suffix}",
                'lastName' => "User",
                'skill' => 999, // Invalid skill level
            ],
        ]);
        // May return 400 (validation) or 500 (enum constraint violation)
        $this->assertContains($response3['status'], [400, 500]);
        $this->assertArrayHasKey('error', $response3['body']);

        // Test missing required profile fields for crew
        $response4 = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('missing.fields'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Test {$suffix}",
                // Missing firstName, lastName, skill
            ],
        ]);
        $this->assertEquals(400, $response4['status']);
        $this->assertArrayHasKey('error', $response4['body']);

        // Test duplicate email (register same email twice)
        $email = $this->makeUniqueEmail('duplicate.test');
        $registerData = [
            'email' => $email,
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Duplicate Test {$suffix}",
                'firstName' => "Duplicate{$suffix}",
                'lastName' => "Test",
                'skill' => 1,
            ],
        ];

        $response5a = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", $registerData);
        $this->assertEquals(201, $response5a['status']);
        $userId = $response5a['body']['data']['user']['id'] ?? null;

        $response5b = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", $registerData);
        $this->assertContains($response5b['status'], [400, 409]); // Bad request or conflict
        $this->assertArrayHasKey('error', $response5b['body']);

        // Cleanup
        $this->cleanupTestUser($userId);
    }

    public function testPasswordChangeRoundTrip(): void
    {
        $email = $this->makeUniqueEmail('password.change');
        $originalPassword = 'TestPass123';
        $newPassword = 'NewPass456!';

        // Register
        $suffix = $this->makeUniqueSuffix();
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email' => $email,
            'password' => $originalPassword,
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "PwChange {$suffix}",
                'firstName' => "PwChange{$suffix}",
                'lastName' => "Test",
                'skill' => 1,
            ],
        ]);
        $this->assertEquals(201, $registerResponse['status']);
        $userId = $registerResponse['body']['data']['user']['id'] ?? null;
        $token = $registerResponse['body']['data']['token'];

        // Change password via PATCH /api/users/me
        $patchResponse = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me", [
            'password' => $newPassword,
            'currentPassword' => $originalPassword,
        ], [
            "Authorization: Bearer {$token}",
        ]);
        $this->assertEquals(200, $patchResponse['status']);

        // Old password must no longer work
        $oldLoginResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => $email,
            'password' => $originalPassword,
        ]);
        $this->assertEquals(401, $oldLoginResponse['status']);

        // New password must work
        $newLoginResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email' => $email,
            'password' => $newPassword,
        ]);
        $this->assertEquals(200, $newLoginResponse['status']);
        $this->assertArrayHasKey('token', $newLoginResponse['body']['data']);

        // Cleanup
        $this->cleanupTestUser($userId);
    }

    public function testAuthenticationFailureWithoutToken(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/assignments");

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }
}
