<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\SQLite\Connection;
use PDO;

/**
 * User Test Helper
 *
 * Provides centralized user creation and deletion for API tests.
 * Each test gets a unique user to ensure test isolation.
 */
class UserTestHelper
{
    private static ?PDO $pdo = null;

    /**
     * Get database connection
     */
    private static function getPdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Connection::getInstance();
        }
        return self::$pdo;
    }

    /**
     * Create a test user with unique identifier
     *
     * @param string $uniqueSuffix Unique suffix for email/names
     * @param string $accountType 'crew' or 'boat_owner'
     * @param array|null $profile Optional profile data (first/last names, etc.)
     * @return array User data including email, password, and created user ID
     */
    public static function createTestUser(
        string $uniqueSuffix,
        string $accountType = 'crew',
        ?array $profile = null
    ): array {
        $pdo = self::getPdo();
        $email = "testuser_{$uniqueSuffix}@example.com";
        $password = 'TestPass123';
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        // Insert user
        $stmt = $pdo->prepare('
            INSERT INTO users (email, password_hash, account_type, is_admin, created_at, updated_at)
            VALUES (:email, :password_hash, :account_type, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');

        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'account_type' => $accountType,
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Create crew or boat profile if needed
        $profileId = null;
        if ($profile !== null) {
            if ($accountType === 'crew') {
                $profileId = self::createCrewProfile($userId, $uniqueSuffix, $profile);
            } elseif ($accountType === 'boat_owner') {
                $profileId = self::createBoatProfile($userId, $uniqueSuffix, $profile);
            }
        }

        return [
            'userId' => $userId,
            'profileId' => $profileId,
            'email' => $email,
            'password' => $password,
            'accountType' => $accountType,
        ];
    }

    /**
     * Create crew profile for a user
     */
    private static function createCrewProfile(int $userId, string $suffix, array $profile): int
    {
        $pdo = self::getPdo();

        $stmt = $pdo->prepare('
            INSERT INTO crews (
                display_name, first_name, last_name, skill, mobile, email,
                user_id, created_at, updated_at
            ) VALUES (
                :display_name, :first_name, :last_name, :skill, :mobile, :email,
                :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');

        $stmt->execute([
            'display_name' => $profile['displayName'] ?? "Test Crew {$suffix}",
            'first_name' => $profile['firstName'] ?? "Test{$suffix}",
            'last_name' => $profile['lastName'] ?? "Crew",
            'skill' => $profile['skill'] ?? 1,
            'mobile' => $profile['mobile'] ?? null,
            'email' => $profile['email'] ?? "testcrew_{$suffix}@example.com",
            'user_id' => $userId,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Create boat profile for a user
     */
    private static function createBoatProfile(int $userId, string $suffix, array $profile): int
    {
        $pdo = self::getPdo();

        $stmt = $pdo->prepare('
            INSERT INTO boats (
                display_name, owner_first_name, owner_last_name, owner_mobile,
                min_berths, max_berths, assistance_required, social_preference,
                owner_user_id, created_at, updated_at
            ) VALUES (
                :display_name, :owner_first_name, :owner_last_name, :owner_mobile,
                :min_berths, :max_berths, :assistance_required, :social_preference,
                :owner_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');

        $stmt->execute([
            'display_name' => $profile['displayName'] ?? "Test Boat {$suffix}",
            'owner_first_name' => $profile['ownerFirstName'] ?? "Test{$suffix}",
            'owner_last_name' => $profile['ownerLastName'] ?? "Owner",
            'owner_mobile' => $profile['ownerMobile'] ?? '555-1234',
            'min_berths' => $profile['minBerths'] ?? 2,
            'max_berths' => $profile['maxBerths'] ?? 4,
            'assistance_required' => $profile['assistanceRequired'] ?? 0,
            'social_preference' => $profile['socialPreference'] ?? 1,
            'owner_user_id' => $userId,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Delete test user and associated profiles
     *
     * @param int $userId User ID to delete
     */
    public static function deleteTestUser(int $userId): void
    {
        $pdo = self::getPdo();

        // Delete crew profile if exists
        $stmt = $pdo->prepare('DELETE FROM crews WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        // Clean up crew_whitelist entries referencing this user's boats
        $stmt = $pdo->prepare('
            DELETE FROM crew_whitelist
            WHERE boat_key IN (SELECT key FROM boats WHERE owner_user_id = :user_id)
        ');
        $stmt->execute(['user_id' => $userId]);

        // Delete boat profile if exists
        $stmt = $pdo->prepare('DELETE FROM boats WHERE owner_user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        // Delete user
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }
}
