<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Auth;

use App\Application\Exception\UserNotFoundException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\UseCase\Auth\LogoutUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogoutUseCaseTest extends TestCase
{
    private function createUser(int $id = 1): User
    {
        $user = new User(
            email: 'test@example.com',
            passwordHash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            accountType: 'crew',
            isAdmin: false
        );
        $user->setId($id);

        return $user;
    }

    public function testExecuteUpdatesLastLogoutTimestamp(): void
    {
        // Arrange
        $userId = 42;
        $user = $this->createUser($userId);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($savedUser) {
                return $savedUser->getLastLogout() instanceof \DateTimeImmutable;
            }));

        $useCase = new LogoutUseCase($userRepository, $this->createMock(LoggerInterface::class));

        // Act
        $useCase->execute($userId);

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getLastLogout());
    }

    public function testExecuteSavesUser(): void
    {
        // Arrange
        $userId = 42;
        $user = $this->createUser($userId);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($user);

        $useCase = new LogoutUseCase($userRepository, $this->createMock(LoggerInterface::class));

        // Act
        $useCase->execute($userId);

        // Assert - Expectations are verified by PHPUnit after test completion
    }

    public function testExecuteThrowsExceptionWhenUserNotFound(): void
    {
        // Arrange
        $userId = 999;

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn(null);

        $userRepository->expects($this->never())
            ->method('save');

        $useCase = new LogoutUseCase($userRepository, $this->createMock(LoggerInterface::class));

        // Assert
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("User with ID {$userId} not found");

        // Act
        $useCase->execute($userId);
    }

    public function testExecuteUsesCurrentTime(): void
    {
        // Arrange
        $userId = 42;
        $user = $this->createUser($userId);
        $beforeExecution = new \DateTimeImmutable();

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($user);

        $useCase = new LogoutUseCase($userRepository, $this->createMock(LoggerInterface::class));

        // Act
        $useCase->execute($userId);
        $afterExecution = new \DateTimeImmutable();

        // Assert
        $lastLogout = $user->getLastLogout();
        $this->assertNotNull($lastLogout);
        $this->assertGreaterThanOrEqual($beforeExecution, $lastLogout);
        $this->assertLessThanOrEqual($afterExecution, $lastLogout);
    }

    public function testExecuteUpdatesUserUpdatedAtTimestamp(): void
    {
        // Arrange
        $userId = 42;
        $user = $this->createUser($userId);
        $originalUpdatedAt = $user->getUpdatedAt();

        sleep(1);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($user);

        $useCase = new LogoutUseCase($userRepository, $this->createMock(LoggerInterface::class));

        // Act
        $useCase->execute($userId);

        // Assert
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }
}
