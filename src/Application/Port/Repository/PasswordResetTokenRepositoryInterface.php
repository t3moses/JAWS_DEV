<?php

declare(strict_types=1);

namespace App\Application\Port\Repository;

/**
 * Password Reset Token Repository Interface
 *
 * Contract for storing and retrieving single-use password reset tokens.
 * Tokens are stored as SHA-256 hashes; plain tokens never persist.
 */
interface PasswordResetTokenRepositoryInterface
{
    /**
     * Persist a new token for the given user
     *
     * @param int $userId User ID
     * @param string $tokenHash SHA-256 hash of the plain token
     * @param \DateTimeImmutable $expiresAt Token expiry time
     */
    public function save(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void;

    /**
     * Find a token record by its hash
     *
     * @param string $tokenHash SHA-256 hash of the plain token
     * @return array|null Row with keys user_id, token_hash, expires_at, created_at; or null
     */
    public function findByTokenHash(string $tokenHash): ?array;

    /**
     * Delete a specific token (single-use enforcement)
     *
     * @param string $tokenHash SHA-256 hash of the plain token
     */
    public function deleteByTokenHash(string $tokenHash): void;

    /**
     * Delete all tokens for a user (invalidate prior tokens on new request)
     *
     * @param int $userId User ID
     */
    public function deleteByUserId(int $userId): void;

    /**
     * Delete all expired tokens (housekeeping)
     */
    public function deleteExpired(): void;
}
