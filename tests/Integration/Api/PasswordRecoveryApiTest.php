<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use App\Infrastructure\Persistence\SQLite\Connection;
use PHPUnit\Framework\TestCase;

/**
 * API tests for the password recovery flow
 *
 * POST /api/auth/forgot-password
 * POST /api/auth/reset-password
 */
class PasswordRecoveryApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    // -------------------------------------------------------------------------
    // POST /api/auth/forgot-password
    // -------------------------------------------------------------------------

    public function testForgotPasswordReturns200ForKnownEmail(): void
    {
        $email = $this->makeUniqueEmail('forgot.known');
        $suffix = $this->makeUniqueSuffix();
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email'       => $email,
            'password'    => 'TestPass123',
            'accountType' => 'crew',
            'profile'     => [
                'displayName' => "Forgot Known {$suffix}",
                'firstName'   => "Forgot{$suffix}",
                'lastName'    => 'Known',
                'skill'       => 1,
            ],
        ]);
        $this->assertEquals(201, $registerResponse['status']);
        $userId = $registerResponse['body']['data']['user']['id'] ?? null;

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/forgot-password", [
            'email' => $email,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        $this->cleanupTestUser($userId);
    }

    public function testForgotPasswordReturns200ForUnknownEmail(): void
    {
        // Enumeration protection — must return 200 even for addresses not in the DB
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/forgot-password", [
            'email' => 'nobody.' . uniqid() . '@example.com',
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testForgotPasswordValidationRejectsInvalidEmail(): void
    {
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/forgot-password", [
            'email' => 'not-an-email',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testForgotPasswordValidationRejectsMissingEmail(): void
    {
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/forgot-password", []);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/reset-password
    // -------------------------------------------------------------------------

    public function testResetPasswordSucceedsWithValidToken(): void
    {
        $email = $this->makeUniqueEmail('reset.valid');
        $suffix = $this->makeUniqueSuffix();
        $registerResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/register", [
            'email'       => $email,
            'password'    => 'TestPass123',
            'accountType' => 'crew',
            'profile'     => [
                'displayName' => "Reset Valid {$suffix}",
                'firstName'   => "Reset{$suffix}",
                'lastName'    => 'Valid',
                'skill'       => 1,
            ],
        ]);
        $this->assertEquals(201, $registerResponse['status']);
        $userId = $registerResponse['body']['data']['user']['id'] ?? null;
        $newPassword = 'NewPass456!';

        // Seed a valid token directly in the DB (we cannot receive email in tests)
        $plainToken = bin2hex(random_bytes(16));
        $this->seedResetToken($userId, $plainToken, '+1 hour');

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token'    => $plainToken,
            'password' => $newPassword,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        // Verify the new password actually works for login
        $loginResponse = $this->makeRequest('POST', "{$this->baseUrl}/auth/login", [
            'email'    => $email,
            'password' => $newPassword,
        ]);
        $this->assertEquals(200, $loginResponse['status']);
        $this->assertArrayHasKey('token', $loginResponse['body']['data']);

        $this->cleanupTestUser($userId);
    }

    public function testResetPasswordTokenIsConsumedAfterUse(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $plainToken = bin2hex(random_bytes(16));
        $this->seedResetToken($testData['userId'], $plainToken, '+1 hour');

        // First use — should succeed
        $first = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token'    => $plainToken,
            'password' => 'FirstNew456!',
        ]);
        $this->assertEquals(200, $first['status']);

        // Second use of the same token — must be rejected
        $second = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token'    => $plainToken,
            'password' => 'SecondNew456!',
        ]);
        $this->assertEquals(400, $second['status']);
        $this->assertArrayHasKey('error', $second['body']);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testResetPasswordRejectsExpiredToken(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $plainToken = bin2hex(random_bytes(16));
        $this->seedResetToken($testData['userId'], $plainToken, '2020-01-01 00:00:00');

        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token'    => $plainToken,
            'password' => 'NewPass456!',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testResetPasswordRejectsUnknownToken(): void
    {
        $response = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token'    => bin2hex(random_bytes(16)),
            'password' => 'NewPass456!',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testResetPasswordValidationRejectsMissingFields(): void
    {
        // Missing token
        $r1 = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'password' => 'NewPass456!',
        ]);
        $this->assertEquals(400, $r1['status']);
        $this->assertArrayHasKey('error', $r1['body']);

        // Missing password
        $r2 = $this->makeRequest('POST', "{$this->baseUrl}/auth/reset-password", [
            'token' => 'sometoken',
        ]);
        $this->assertEquals(400, $r2['status']);
        $this->assertArrayHasKey('error', $r2['body']);
    }

    // -------------------------------------------------------------------------

    /**
     * Insert a plain-text token (stored as its SHA-256 hash) for the given user.
     */
    private function seedResetToken(int $userId, string $plainToken, string $expiresIn): void
    {
        $pdo = Connection::getInstance();
        $stmt = $pdo->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
            VALUES (:user_id, :token_hash, :expires_at, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => (new \DateTimeImmutable($expiresIn))->format('Y-m-d H:i:s'),
        ]);
    }
}
