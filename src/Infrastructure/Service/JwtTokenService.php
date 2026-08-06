<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\TokenServiceInterface;

/**
 * JWT Token Service
 *
 * Implements JWT token generation and validation using native PHP (no external libraries).
 * Uses HS256 (HMAC-SHA256) algorithm for token signing.
 *
 * Token Payload Structure:
 * - sub: User ID
 * - email: User email
 * - account_type: Account type ('crew' or 'boat_owner')
 * - is_admin: Admin status (boolean)
 * - iat: Issued at timestamp
 * - exp: Expiration timestamp
 */
class JwtTokenService implements TokenServiceInterface
{
    private string $secret;
    private int $expirationMinutes;

    /**
     * @param string $secret Secret key for signing tokens
     * @param int $expirationMinutes Token expiration time in minutes
     */
    public function __construct(string $secret, int $expirationMinutes = 60)
    {
        $this->secret = $secret;
        $this->expirationMinutes = $expirationMinutes;
    }

    /**
     * Generate JWT token for user
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $accountType Account type ('crew' or 'boat_owner')
     * @param bool $isAdmin Admin status
     * @return string JWT token
     */
    public function generate(int $userId, string $email, string $accountType, bool $isAdmin): string
    {
        // Header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ]);

        // Payload
        $payload = json_encode([
            'sub' => $userId,
            'email' => $email,
            'account_type' => $accountType,
            'is_admin' => $isAdmin,
            'iat' => time(),
            'exp' => time() + ($this->expirationMinutes * 60),
        ]);

        // Encode Header
        $base64UrlHeader = $this->base64UrlEncode($header);

        // Encode Payload
        $base64UrlPayload = $this->base64UrlEncode($payload);

        // Create Signature
        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $this->secret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        // Create JWT
        return "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
    }

    /**
     * Validate and decode JWT token
     *
     * @param string $token JWT token to validate
     * @return array|null Decoded payload or null if invalid/expired
     */
    public function validate(string $token): ?array
    {
        // Split token into parts
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        // Verify signature
        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $this->secret, true);
        $expectedSignature = $this->base64UrlEncode($signature);

        if (!hash_equals($expectedSignature, $base64UrlSignature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);

        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        // Verify required fields
        if (!isset($payload['sub'], $payload['email'], $payload['account_type'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Get token expiration time in minutes
     *
     * @return int Expiration time in minutes
     */
    public function getExpirationMinutes(): int
    {
        return $this->expirationMinutes;
    }

    /**
     * Base64 URL encode
     *
     * @param string $data Data to encode
     * @return string Base64 URL encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     *
     * @param string $data Base64 URL encoded data
     * @return string Decoded data
     */
    private function base64UrlDecode(string $data): string
    {
        // Add padding if needed
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLength = 4 - $remainder;
            $data .= str_repeat('=', $padLength);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }
}
