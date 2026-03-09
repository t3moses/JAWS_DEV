<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\DTO\Request\LoginRequest;
use App\Application\DTO\Response\AuthResponse;
use App\Application\DTO\Response\UserResponse;
use App\Application\Exception\InvalidCredentialsException;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use App\Application\Port\Service\TokenServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Login Use Case
 *
 * Handles user authentication with email and password.
 * Returns JWT token on successful login.
 */
class LoginUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasswordServiceInterface $passwordService,
        private TokenServiceInterface $tokenService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute login
     *
     * @param LoginRequest $request Login request
     * @return AuthResponse Authentication response with token
     * @throws ValidationException If validation fails
     * @throws InvalidCredentialsException If credentials are invalid
     */
    public function execute(LoginRequest $request): AuthResponse
    {
        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Find user by email
        $user = $this->userRepository->findByEmail($request->email);
        if ($user === null) {
            throw new InvalidCredentialsException();
        }

        // Verify password
        if (!$this->passwordService->verify($request->password, $user->getPasswordHash())) {
            throw new InvalidCredentialsException();
        }

        // Update last login
        $user->updateLastLogin(new \DateTimeImmutable());
        $this->userRepository->save($user);

        // Generate JWT token
        $token = $this->tokenService->generate(
            $user->getId(),
            $user->getEmail(),
            $user->getAccountType(),
            $user->isAdmin()
        );

        $this->logger->info('auth.login', [
            'user_id'      => $user->getId(),
            'email'        => $user->getEmail(),
            'account_type' => $user->getAccountType(),
            'is_admin'     => $user->isAdmin(),
        ]);

        // Create response
        return new AuthResponse(
            token: $token,
            user: UserResponse::fromEntity($user),
            expiresIn: $this->tokenService->getExpirationMinutes() * 60, // Convert to seconds
        );
    }
}
