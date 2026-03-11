<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Auth;

use App\Application\UseCase\Auth\LoginUseCase;
use App\Application\DTO\Request\LoginRequest;
use App\Application\Exception\InvalidCredentialsException;
use App\Application\Exception\ValidationException;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Infrastructure\Service\PhpPasswordService;
use App\Infrastructure\Service\JwtTokenService;
use App\Domain\Entity\User;
use Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for LoginUseCase
 *
 * Tests user authentication with email and password.
 */
class LoginUseCaseTest extends IntegrationTestCase
{
    private LoginUseCase $useCase;
    private UserRepository $userRepository;
    private PhpPasswordService $passwordService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = new UserRepository();
        $this->passwordService = new PhpPasswordService();
        $tokenService = new JwtTokenService();

        $this->useCase = new LoginUseCase(
            $this->userRepository,
            $this->passwordService,
            $tokenService,
            new NullLogger()
        );
    }

    public function testLoginWithValidCredentialsReturnsAuthResponse(): void
    {
        // Create user
        $password = 'SecurePassword123';
        $user = new User(
            email: 'login@example.com',
            passwordHash: $this->passwordService->hash($password),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // Attempt login
        $request = new LoginRequest(
            email: 'login@example.com',
            password: $password
        );

        $response = $this->useCase->execute($request);

        // Assertions
        $this->assertNotNull($response->token);
        $this->assertNotEmpty($response->token);
        $this->assertEquals('login@example.com', $response->user->email);
        $this->assertEquals('crew', $response->user->accountType);
        $this->assertGreaterThan(0, $response->expiresIn);
    }

    public function testLoginUpdatesLastLoginTimestamp(): void
    {
        // Create user
        $password = 'SecurePassword123';
        $user = new User(
            email: 'lastlogin@example.com',
            passwordHash: $this->passwordService->hash($password),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);
        $userId = $user->getId();

        // Login
        $request = new LoginRequest(
            email: 'lastlogin@example.com',
            password: $password
        );
        $this->useCase->execute($request);

        // Verify last login was updated
        $updatedUser = $this->userRepository->findById($userId);
        $this->assertNotNull($updatedUser->getLastLogin());
        $expectedDate = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $this->assertEquals($expectedDate, $updatedUser->getLastLogin()->format('Y-m-d'));
    }

    public function testLoginWithNonExistentEmailThrowsException(): void
    {
        $request = new LoginRequest(
            email: 'nonexistent@example.com',
            password: 'password'
        );

        $this->expectException(InvalidCredentialsException::class);

        $this->useCase->execute($request);
    }

    public function testLoginWithWrongPasswordThrowsException(): void
    {
        // Create user
        $user = new User(
            email: 'wrong@example.com',
            passwordHash: $this->passwordService->hash('CorrectPassword123'),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // Attempt with wrong password
        $request = new LoginRequest(
            email: 'wrong@example.com',
            password: 'WrongPassword123'
        );

        $this->expectException(InvalidCredentialsException::class);

        $this->useCase->execute($request);
    }

    public function testLoginWithEmptyEmailThrowsValidationException(): void
    {
        $request = new LoginRequest(
            email: '',
            password: 'password'
        );

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testLoginWithEmptyPasswordThrowsValidationException(): void
    {
        $request = new LoginRequest(
            email: 'test@example.com',
            password: ''
        );

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testLoginWithInvalidEmailFormatThrowsValidationException(): void
    {
        $request = new LoginRequest(
            email: 'not-an-email',
            password: 'password'
        );

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testLoginWithBoatOwnerAccountReturnsCorrectType(): void
    {
        // Create boat owner user
        $password = 'SecurePassword123';
        $user = new User(
            email: 'boatowner@example.com',
            passwordHash: $this->passwordService->hash($password),
            accountType: 'boat_owner',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // Login
        $request = new LoginRequest(
            email: 'boatowner@example.com',
            password: $password
        );

        $response = $this->useCase->execute($request);

        $this->assertEquals('boat_owner', $response->user->accountType);
    }

    public function testLoginWithAdminUserReturnsAdminFlag(): void
    {
        // Create admin user
        $password = 'SecurePassword123';
        $user = new User(
            email: 'admin@example.com',
            passwordHash: $this->passwordService->hash($password),
            accountType: 'crew',
            isAdmin: true
        );
        $this->userRepository->save($user);

        // Login
        $request = new LoginRequest(
            email: 'admin@example.com',
            password: $password
        );

        $response = $this->useCase->execute($request);

        $this->assertTrue($response->user->isAdmin);
    }

    public function testMultipleLoginsGenerateDifferentTokens(): void
    {
        // Create user
        $password = 'SecurePassword123';
        $user = new User(
            email: 'multilogin@example.com',
            passwordHash: $this->passwordService->hash($password),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // First login
        $request = new LoginRequest(
            email: 'multilogin@example.com',
            password: $password
        );
        $response1 = $this->useCase->execute($request);

        // Wait to ensure different timestamp in JWT
        sleep(1);

        // Second login
        $response2 = $this->useCase->execute($request);

        // Tokens should be different (different timestamps)
        $this->assertNotEquals($response1->token, $response2->token);
    }

    public function testTokenExpirationTimeIsReasonable(): void
    {
        // Create user
        $user = new User(
            email: 'expiration@example.com',
            passwordHash: $this->passwordService->hash('SecurePassword123'),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // Login
        $request = new LoginRequest(
            email: 'expiration@example.com',
            password: 'SecurePassword123'
        );

        $response = $this->useCase->execute($request);

        // Token should expire between 1 hour (3600 seconds) and 7 days (604800 seconds)
        $this->assertGreaterThanOrEqual(3600, $response->expiresIn);
        $this->assertLessThanOrEqual(604800, $response->expiresIn);
    }
}
