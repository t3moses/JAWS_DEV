<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\PasswordServiceInterface;

/**
 * PHP Password Service
 *
 * Implements password hashing and verification using PHP's native password_hash() and password_verify() functions.
 * Uses BCRYPT algorithm with cost factor 12 for strong security.
 *
 * Password Requirements:
 * - Minimum 8 characters
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one number
 */
class PhpPasswordService implements PasswordServiceInterface
{
    private const HASH_COST = 12;
    private const MIN_LENGTH = 8;

    /**
     * Hash a plaintext password
     *
     * @param string $password Plaintext password
     * @return string Hashed password
     * @throws \RuntimeException If password hashing fails
     */
    public function hash(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::HASH_COST]);
        if ($hash === false) {
            throw new \RuntimeException('Failed to hash password. Check PHP bcrypt configuration.');
        }
        return $hash;
    }

    /**
     * Verify a password against a hash
     *
     * @param string $password Plaintext password to verify
     * @param string $hash Hashed password to verify against
     * @return bool True if password matches hash, false otherwise
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password meets security requirements
     *
     * Requirements:
     * - Minimum 8 characters
     * - At least one uppercase letter (A-Z)
     * - At least one lowercase letter (a-z)
     * - At least one number (0-9)
     *
     * @param string $password Password to validate
     * @return bool True if password meets requirements, false otherwise
     */
    public function meetsRequirements(string $password): bool
    {
        // Check minimum length
        if (strlen($password) < self::MIN_LENGTH) {
            return false;
        }

        // Check for at least one uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }

        // Check for at least one lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }

        // Check for at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }

        return true;
    }

    /**
     * Get password requirements message
     *
     * @return string Human-readable requirements description
     */
    public function getRequirementsMessage(): string
    {
        return sprintf(
            'Password must be at least %d characters long and contain at least one uppercase letter, one lowercase letter, and one number',
            self::MIN_LENGTH
        );
    }
}
