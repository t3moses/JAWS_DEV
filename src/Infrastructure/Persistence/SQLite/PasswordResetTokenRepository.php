<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use PDO;

/**
 * Password Reset Token Repository
 *
 * SQLite implementation of PasswordResetTokenRepositoryInterface.
 * Handles persistence of hashed password reset tokens.
 */
class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    /**
     * Persist a new token for the given user
     *
     * @param int $userId User ID
     * @param string $tokenHash SHA-256 hash of the plain token
     * @param \DateTimeImmutable $expiresAt Token expiry time
     */
    public function save(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
            VALUES (:user_id, :token_hash, :expires_at, :created_at)
        ');

        $stmt->execute([
            'user_id'    => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Find a token record by its hash
     *
     * @param string $tokenHash SHA-256 hash of the plain token
     * @return array|null Row or null if not found
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1
        ');

        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Delete a specific token (single-use enforcement)
     *
     * @param string $tokenHash SHA-256 hash of the plain token
     */
    public function deleteByTokenHash(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM password_reset_tokens WHERE token_hash = :token_hash
        ');

        $stmt->execute(['token_hash' => $tokenHash]);
    }

    /**
     * Delete all tokens for a user (invalidate prior tokens on new request)
     *
     * @param int $userId User ID
     */
    public function deleteByUserId(int $userId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM password_reset_tokens WHERE user_id = :user_id
        ');

        $stmt->execute(['user_id' => $userId]);
    }

    /**
     * Delete all expired tokens (housekeeping)
     */
    public function deleteExpired(): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM password_reset_tokens WHERE expires_at < :now
        ');

        $stmt->execute(['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }
}
