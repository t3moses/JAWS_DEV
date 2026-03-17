<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\Request\ForgotPasswordRequest;
use App\Application\DTO\Request\LoginRequest;
use App\Application\DTO\Request\RegisterRequest;
use App\Application\DTO\Request\ResetPasswordRequest;
use App\Application\UseCase\Auth\ForgotPasswordUseCase;
use App\Application\UseCase\Auth\GetSessionUseCase;
use App\Application\UseCase\Auth\LoginUseCase;
use App\Application\UseCase\Auth\LogoutUseCase;
use App\Application\UseCase\Auth\RegisterUseCase;
use App\Application\UseCase\Auth\ResetPasswordUseCase;
use App\Presentation\Response\JsonResponse;

/**
 * Authentication Controller
 *
 * Handles user authentication endpoints:
 * - POST /api/auth/register - Register new user
 * - POST /api/auth/login - Login with email/password
 * - GET /api/auth/session - Get current session info
 * - POST /api/auth/logout - Logout current user
 * - POST /api/auth/forgot-password - Request password reset email
 * - POST /api/auth/reset-password - Reset password with token
 */
class AuthController
{
    public function __construct(
        private RegisterUseCase $registerUseCase,
        private LoginUseCase $loginUseCase,
        private GetSessionUseCase $getSessionUseCase,
        private LogoutUseCase $logoutUseCase,
        private ForgotPasswordUseCase $forgotPasswordUseCase,
        private ResetPasswordUseCase $resetPasswordUseCase,
    ) {
    }

    /**
     * Register new user
     *
     * POST /api/auth/register
     *
     * @param array $body Request body
     * @return JsonResponse
     */
    public function register(array $body): JsonResponse
    {
        $request = RegisterRequest::fromArray($body);
        $response = $this->registerUseCase->execute($request);

        $data = $response->toArray();
        $data['message'] = 'Registration successful';

        return JsonResponse::success($data, 201);
    }

    /**
     * Login with email and password
     *
     * POST /api/auth/login
     *
     * @param array $body Request body
     * @return JsonResponse
     */
    public function login(array $body): JsonResponse
    {
        $request = LoginRequest::fromArray($body);
        $response = $this->loginUseCase->execute($request);

        return JsonResponse::success($response->toArray());
    }

    /**
     * Get current session information
     *
     * GET /api/auth/session
     *
     * @param array $auth Authentication context from JWT middleware
     * @return JsonResponse
     */
    public function getSession(array $auth): JsonResponse
    {
        $response = $this->getSessionUseCase->execute($auth['user_id']);

        return JsonResponse::success([
            'user' => $response->toArray(),
        ]);
    }

    /**
     * Logout current user
     *
     * POST /api/auth/logout
     *
     * Updates the user's last_logout timestamp for audit trail.
     * Note: JWT remains technically valid until expiration. Client must
     * delete the token from storage to complete logout.
     *
     * @param array $auth Authentication context from JWT middleware
     * @return JsonResponse
     */
    public function logout(array $auth): JsonResponse
    {
        $this->logoutUseCase->execute($auth['user_id']);

        return JsonResponse::success([
            'message' => 'Logout successful. Please delete your token.',
        ]);
    }

    /**
     * Request a password reset email
     *
     * POST /api/auth/forgot-password
     *
     * Always returns 200 regardless of whether the email is registered
     * to prevent email enumeration attacks.
     *
     * @param array $body Request body
     * @return JsonResponse
     */
    public function forgotPassword(array $body): JsonResponse
    {
        $this->forgotPasswordUseCase->execute(ForgotPasswordRequest::fromArray($body));

        return JsonResponse::success([
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    /**
     * Reset password using a valid token
     *
     * POST /api/auth/reset-password
     *
     * @param array $body Request body
     * @return JsonResponse
     */
    public function resetPassword(array $body): JsonResponse
    {
        $this->resetPasswordUseCase->execute(ResetPasswordRequest::fromArray($body));

        return JsonResponse::success([
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
