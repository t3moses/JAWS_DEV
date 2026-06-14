<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\TokenServiceInterface;
use App\Presentation\Response\JsonResponse;

/**
 * JWT Authentication Middleware
 *
 * Extracts and validates JWT token from Authorization header.
 * Replaces NameAuthMiddleware for token-based authentication.
 *
 * Headers:
 * - Authorization: Bearer <token>
 */
class JwtAuthMiddleware
{
    public function __construct(
        private TokenServiceInterface $tokenService,
        private UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * Process authentication
     *
     * Extracts JWT token from Authorization header and validates it.
     *
     * @return array|null Authentication context or null if authentication failed
     *                    Context structure: ['user_id' => int, 'email' => string, 'account_type' => string, 'is_admin' => bool]
     */
    public function authenticate(): ?array
    {
        // Extract headers
        $headers = $this->getHeaders();

        // Get Authorization header
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (empty($authHeader)) {
            return null;
        }

        // Parse "Bearer <token>" format
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = $matches[1];

        // Validate and decode token
        $payload = $this->tokenService->validate($token);

        if ($payload === null) {
            return null;
        }

        // Reject tokens belonging to suspended (disabled) or deleted accounts.
        // This makes deactivation take effect immediately rather than waiting
        // for the existing token to expire.
        $user = $this->userRepository->findById((int)$payload['sub']);
        if ($user === null || $user->isDisabled()) {
            return null;
        }

        // Return authentication context
        return [
            'user_id' => $payload['sub'],
            'email' => $payload['email'],
            'account_type' => $payload['account_type'],
            'is_admin' => $payload['is_admin'] ?? false,
        ];
    }

    /**
     * Get all HTTP headers
     *
     * @return array
     */
    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        // Fallback for servers that don't support getallheaders()
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    /**
     * Create authentication failed response
     *
     * @return JsonResponse
     */
    public function authenticationFailed(): JsonResponse
    {
        return JsonResponse::error(
            'Authentication required. Please provide a valid JWT token in the Authorization header.',
            401
        );
    }
}
