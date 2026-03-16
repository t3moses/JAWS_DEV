<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Auth;

use App\Application\DTO\Request\ResetPasswordRequest;
use App\Application\Exception\InvalidResetTokenException;
use App\Application\Exception\ValidationException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use App\Application\UseCase\Auth\ResetPasswordUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ResetPasswordUseCaseTest extends TestCase
{
    private PasswordResetTokenRepositoryInterface $tokenRepository;
    private UserRepositoryInterface $userRepository;
    private PasswordServiceInterface $passwordService;
    private ResetPasswordUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenRepository = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $this->userRepository  = $this->createMock(UserRepositoryInterface::class);
        $this->passwordService = $this->createMock(PasswordServiceInterface::class);

        $this->passwordService->method('meetsRequirements')->willReturn(true);
        $this->passwordService->method('hash')->willReturn('$2y$hashed');

        $this->useCase = new ResetPasswordUseCase(
            $this->tokenRepository,
            $this->userRepository,
            $this->passwordService,
            new NullLogger(),
        );
    }

    public function testThrowsValidationExceptionWhenTokenMissing(): void
    {
        $this->expectException(ValidationException::class);

        $this->useCase->execute(new ResetPasswordRequest('', 'NewPass123!'));
    }

    public function testThrowsValidationExceptionWhenPasswordMissing(): void
    {
        $this->expectException(ValidationException::class);

        $this->useCase->execute(new ResetPasswordRequest('sometoken', ''));
    }

    public function testThrowsInvalidResetTokenExceptionForUnknownToken(): void
    {
        $this->tokenRepository->method('findByTokenHash')->willReturn(null);

        $this->expectException(InvalidResetTokenException::class);

        $this->useCase->execute(new ResetPasswordRequest('unknowntoken', 'NewPass123!'));
    }

    public function testThrowsInvalidResetTokenExceptionForExpiredToken(): void
    {
        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 1,
            'token_hash' => hash('sha256', 'expiredtoken'),
            'expires_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $this->expectException(InvalidResetTokenException::class);

        $this->useCase->execute(new ResetPasswordRequest('expiredtoken', 'NewPass123!'));
    }

    public function testDeletesExpiredTokenBeforeThrowing(): void
    {
        $hash = hash('sha256', 'expiredtoken');

        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 1,
            'token_hash' => $hash,
            'expires_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $this->tokenRepository->expects($this->once())
            ->method('deleteByTokenHash')
            ->with($hash);

        try {
            $this->useCase->execute(new ResetPasswordRequest('expiredtoken', 'NewPass123!'));
        } catch (InvalidResetTokenException) {
            // expected
        }
    }

    public function testThrowsWeakPasswordExceptionWhenPasswordTooWeak(): void
    {
        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 1,
            'token_hash' => hash('sha256', 'validtoken'),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        // Rebuild password service mock so willReturn(false) is the only stub
        $weakPasswordService = $this->createMock(PasswordServiceInterface::class);
        $weakPasswordService->method('meetsRequirements')->willReturn(false);
        $weakPasswordService->method('getRequirementsMessage')->willReturn('Password too weak');

        $useCase = new ResetPasswordUseCase(
            $this->tokenRepository,
            $this->userRepository,
            $weakPasswordService,
            new NullLogger(),
        );

        $this->expectException(WeakPasswordException::class);

        $useCase->execute(new ResetPasswordRequest('validtoken', 'weak'));
    }

    public function testThrowsInvalidResetTokenExceptionWhenUserDeleted(): void
    {
        $hash = hash('sha256', 'orphantoken');

        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 99,
            'token_hash' => $hash,
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $this->userRepository->method('findById')->willReturn(null);

        $this->tokenRepository->expects($this->once())
            ->method('deleteByTokenHash')
            ->with($hash);

        $this->expectException(InvalidResetTokenException::class);

        $this->useCase->execute(new ResetPasswordRequest('orphantoken', 'NewPass123!'));
    }

    public function testSuccessfullyResetsPassword(): void
    {
        $plainToken = 'validtoken';
        $hash       = hash('sha256', $plainToken);

        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 5,
            'token_hash' => $hash,
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $user = $this->makeUser(5, 'user@example.com');
        $this->userRepository->method('findById')->willReturn($user);

        $this->userRepository->expects($this->once())->method('save')->with($user);

        $this->useCase->execute(new ResetPasswordRequest($plainToken, 'NewPass123!'));

        $this->assertEquals('$2y$hashed', $user->getPasswordHash());
    }

    public function testDeletesTokenAfterSuccessfulReset(): void
    {
        $plainToken = 'validtoken';
        $hash       = hash('sha256', $plainToken);

        $this->tokenRepository->method('findByTokenHash')->willReturn([
            'user_id'    => 5,
            'token_hash' => $hash,
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $this->userRepository->method('findById')->willReturn($this->makeUser(5, 'user@example.com'));

        $this->tokenRepository->expects($this->once())
            ->method('deleteByTokenHash')
            ->with($hash);

        $this->useCase->execute(new ResetPasswordRequest($plainToken, 'NewPass123!'));
    }

    public function testHashesTokenBeforeLookup(): void
    {
        $plainToken = 'myplaintoken';

        $capturedHash = null;
        $this->tokenRepository->method('findByTokenHash')
            ->willReturnCallback(function (string $hash) use (&$capturedHash) {
                $capturedHash = $hash;
                return null; // will throw InvalidResetTokenException, that's fine
            });

        try {
            $this->useCase->execute(new ResetPasswordRequest($plainToken, 'NewPass123!'));
        } catch (InvalidResetTokenException) {
            // expected
        }

        $this->assertEquals(hash('sha256', $plainToken), $capturedHash);
    }

    // -------------------------------------------------------------------------

    private function makeUser(int $id, string $email): User
    {
        $user = new User($email, 'old_hash', 'crew');
        $reflection = new \ReflectionClass($user);
        $prop = $reflection->getProperty('id');
        $prop->setValue($user, $id);
        return $user;
    }
}
