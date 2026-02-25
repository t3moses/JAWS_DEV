<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

/**
 * Shared utilities for API tests
 */
trait ApiTestTrait
{
    private function makeRequest(string $method, string $url, ?array $body = null, ?array $headers = []): array
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        if ($body !== null) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            throw new \RuntimeException("Curl request failed: {$error}");
        }

        // Note: curl_close() is deprecated in PHP 8.5+ as handles auto-close since PHP 8.0

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true),
        ];
    }

    private function makeUniqueEmail(string $prefix): string
    {
        $suffix = str_replace('.', '', (string)microtime(true));
        return sprintf('%s.%s@example.com', $prefix, $suffix);
    }

    private function makeUniqueSuffix(): string
    {
        return str_replace('.', '', (string)microtime(true));
    }

    private function createTestCrew(string $baseUrl): array
    {
        $suffix = $this->makeUniqueSuffix();
        $firstName = "TestCrew{$suffix}";
        $lastName = "Member";

        $response = $this->makeRequest('POST', "{$baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('test.crew'),
            'password' => 'TestPass123',
            'accountType' => 'crew',
            'profile' => [
                'displayName' => "Test Crew {$suffix}",
                'firstName' => $firstName,
                'lastName' => $lastName,
                'skill' => 1,
            ],
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('token', $response['body']['data']);

        return [
            'token' => $response['body']['data']['token'],
            'userId' => $response['body']['data']['user']['id'] ?? null,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ];
    }

    private function createTestBoatOwner(string $baseUrl): array
    {
        $suffix = $this->makeUniqueSuffix();
        $ownerFirstName = "TestBoat{$suffix}";
        $ownerLastName = "Owner";

        $response = $this->makeRequest('POST', "{$baseUrl}/auth/register", [
            'email' => $this->makeUniqueEmail('test.boat'),
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
        $this->assertArrayHasKey('token', $response['body']['data']);

        return [
            'token' => $response['body']['data']['token'],
            'userId' => $response['body']['data']['user']['id'] ?? null,
            'ownerFirstName' => $ownerFirstName,
            'ownerLastName' => $ownerLastName,
        ];
    }

    private function createTestAdmin(string $baseUrl): array
    {
        $suffix = $this->makeUniqueSuffix();
        $email = $this->makeUniqueEmail('test.admin');
        $password = 'AdminPass123';

        // Create admin user directly in database (bypasses register endpoint's isAdmin=false)
        $pdo = \App\Infrastructure\Persistence\SQLite\Connection::getInstance();
        $stmt = $pdo->prepare('
            INSERT INTO users (email, password_hash, account_type, is_admin, created_at, updated_at)
            VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            'crew', // Admin can have any account type
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Create a crew profile for the admin (required for some operations)
        $firstName = "AdminTest{$suffix}";
        $lastName = "User";

        // Generate crew key: lowercase firstname + lastname (no spaces)
        $crewKey = strtolower(str_replace(' ', '', $firstName) . str_replace(' ', '', $lastName));

        $stmt = $pdo->prepare('
            INSERT INTO crews (
                key, display_name, first_name, last_name, skill, user_id,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $crewKey,
            "Admin Test {$suffix}",
            $firstName,
            $lastName,
            $userId,
        ]);

        // Login to get a valid JWT token with is_admin=true
        $response = $this->makeRequest('POST', "{$baseUrl}/auth/login", [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('token', $response['body']['data']);

        return [
            'token' => $response['body']['data']['token'],
            'userId' => $userId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
        ];
    }

    private function cleanupTestUser(?int $userId): void
    {
        if ($userId) {
            \Tests\Integration\UserTestHelper::deleteTestUser($userId);
        }
    }
}
