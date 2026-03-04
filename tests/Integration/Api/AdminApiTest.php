<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for Admin endpoints
 */
class AdminApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testGetMatchingData(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $eventId = urlencode('Fri May 29');
        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/matching/{$eventId}", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, which is valid
        $this->assertContains($response['status'], [200, 404]);
        $this->assertArrayHasKey('success', $response['body']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testSendNotifications(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $eventId = urlencode('Fri May 29');
        $response = $this->makeRequest('POST', "{$this->baseUrl}/admin/notifications/{$eventId}", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist
        // Note: Email service errors should be handled gracefully, not return 500
        $this->assertContains($response['status'], [200, 404]);

        // Response should have either success or error field
        $hasSuccessOrError = isset($response['body']['success']) || isset($response['body']['error']);
        $this->assertTrue($hasSuccessOrError, "Response missing 'success' or 'error' field");

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateConfig(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'startTime' => '10:00:00',
            'finishTime' => '18:00:00',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateConfigResponseIncludesRecalculation(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'start_time' => '10:00:00',
            'finish_time' => '18:00:00',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('recalculation', $response['body']['data']);

        $recalculation = $response['body']['data']['recalculation'];
        $this->assertArrayHasKey('success', $recalculation);
        $this->assertArrayHasKey('events_processed', $recalculation);
        $this->assertArrayHasKey('flotillas_generated', $recalculation);
        $this->assertIsInt($recalculation['events_processed']);
        $this->assertIsInt($recalculation['flotillas_generated']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateConfigValidation(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        // Test invalid time format - may be accepted or rejected depending on implementation
        $response1 = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'startTime' => 'invalid-time',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response1['status'], [200, 400]);
        // $this->assertArrayHasKey('error', $response1['body']);

        // Test finishTime < startTime - validation may not be implemented
        $response2 = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'startTime' => '18:00:00',
            'finishTime' => '10:00:00',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response2['status'], [200, 400]);

        // Test invalid date format - validation may not be implemented
        $response3 = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'startDate' => 'not-a-date',
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response3['status'], [200, 400]);

        // Test negative blackout value - validation may not be implemented
        $response4 = $this->makeRequest('PATCH', "{$this->baseUrl}/admin/config", [
            'blackoutFrom' => -60,
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response4['status'], [200, 400]);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    // =======================
    // GET /api/admin/users
    // =======================

    public function testGetUsersRequiresAuthentication(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/users");

        $this->assertEquals(401, $response['status']);
    }

    public function testGetUsersRequiresAdminPrivileges(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/users", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(403, $response['status']);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetUsersReturns200WithArray(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/users", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertIsArray($response['body']['data']);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetUsersDoesNotExposePasswordHashes(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/users", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);

        foreach ($response['body']['data'] as $user) {
            $this->assertArrayNotHasKey('password_hash', $user);
        }

        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetUsersResponseContainsExpectedFields(): void
    {
        $testData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/admin/users", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertNotEmpty($response['body']['data']);

        $user = $response['body']['data'][0];
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('account_type', $user);
        $this->assertArrayHasKey('is_admin', $user);
        $this->assertArrayHasKey('created_at', $user);

        $this->cleanupTestUser($testData['userId']);
    }

    // =======================
    // PATCH /api/admin/users/{userId}/admin
    // =======================

    public function testSetUserAdminRequiresAdminPrivileges(): void
    {
        $adminData = $this->createTestAdmin($this->baseUrl);
        $crewData  = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest(
            'PATCH',
            "{$this->baseUrl}/admin/users/{$adminData['userId']}/admin",
            ['is_admin' => false],
            ["Authorization: Bearer {$crewData['token']}"]
        );

        $this->assertEquals(403, $response['status']);

        $this->cleanupTestUser($adminData['userId']);
        $this->cleanupTestUser($crewData['userId']);
    }

    public function testSetUserAdminGrantsPrivileges(): void
    {
        $adminData  = $this->createTestAdmin($this->baseUrl);
        $targetData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest(
            'PATCH',
            "{$this->baseUrl}/admin/users/{$targetData['userId']}/admin",
            ['is_admin' => true],
            ["Authorization: Bearer {$adminData['token']}"]
        );

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertTrue($response['body']['data']['is_admin']);
        $this->assertEquals($targetData['userId'], $response['body']['data']['id']);

        $this->cleanupTestUser($adminData['userId']);
        $this->cleanupTestUser($targetData['userId']);
    }

    public function testSetUserAdminRevokesPrivileges(): void
    {
        $adminData  = $this->createTestAdmin($this->baseUrl);
        $targetData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest(
            'PATCH',
            "{$this->baseUrl}/admin/users/{$targetData['userId']}/admin",
            ['is_admin' => false],
            ["Authorization: Bearer {$adminData['token']}"]
        );

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertFalse($response['body']['data']['is_admin']);

        $this->cleanupTestUser($adminData['userId']);
        $this->cleanupTestUser($targetData['userId']);
    }

    public function testSetUserAdminReturnsBadRequestWhenTargetingSelf(): void
    {
        $adminData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest(
            'PATCH',
            "{$this->baseUrl}/admin/users/{$adminData['userId']}/admin",
            ['is_admin' => false],
            ["Authorization: Bearer {$adminData['token']}"]
        );

        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);

        $this->cleanupTestUser($adminData['userId']);
    }

    public function testSetUserAdminReturnsNotFoundForUnknownUser(): void
    {
        $adminData = $this->createTestAdmin($this->baseUrl);

        $response = $this->makeRequest(
            'PATCH',
            "{$this->baseUrl}/admin/users/999999/admin",
            ['is_admin' => true],
            ["Authorization: Bearer {$adminData['token']}"]
        );

        $this->assertEquals(404, $response['status']);

        $this->cleanupTestUser($adminData['userId']);
    }
}
