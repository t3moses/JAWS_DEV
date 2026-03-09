<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\UseCase\Admin\SetUserAdminUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SetUserAdminUseCaseTest extends TestCase
{
    private function createUser(int $id, bool $isAdmin = false): User
    {
        $user = new User(
            email: "user{$id}@example.com",
            passwordHash: 'hashed_password',
            accountType: 'crew',
            isAdmin: $isAdmin,
        );
        $user->setId($id);

        return $user;
    }

    public function testThrowsValidationExceptionWhenTargetingOwnAccount(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->never())->method('findById');
        $userRepository->expects($this->never())->method('save');

        $useCase = new SetUserAdminUseCase($userRepository, $this->createMock(LoggerInterface::class));

        $this->expectException(ValidationException::class);

        $useCase->execute(targetUserId: 42, isAdmin: true, requestingUserId: 42);
    }

    public function testThrowsRuntimeExceptionWhenUserNotFound(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $userRepository->expects($this->never())->method('save');

        $useCase = new SetUserAdminUseCase($userRepository, $this->createMock(LoggerInterface::class));

        $this->expectException(\RuntimeException::class);

        $useCase->execute(targetUserId: 999, isAdmin: true, requestingUserId: 1);
    }

    public function testGrantsAdminAndPersists(): void
    {
        $targetUser = $this->createUser(id: 5, isAdmin: false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($targetUser);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($u) => $u->isAdmin() === true));

        $useCase = new SetUserAdminUseCase($userRepository, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute(targetUserId: 5, isAdmin: true, requestingUserId: 1);

        $this->assertTrue($targetUser->isAdmin());
        $this->assertTrue($result['is_admin']);
    }

    public function testRevokesAdminAndPersists(): void
    {
        $targetUser = $this->createUser(id: 5, isAdmin: true);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($targetUser);

        $userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($u) => $u->isAdmin() === false));

        $useCase = new SetUserAdminUseCase($userRepository, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute(targetUserId: 5, isAdmin: false, requestingUserId: 1);

        $this->assertFalse($targetUser->isAdmin());
        $this->assertFalse($result['is_admin']);
    }

    public function testReturnedSummaryContainsExpectedFields(): void
    {
        $targetUser = $this->createUser(id: 7, isAdmin: false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->willReturn($targetUser);
        $userRepository->method('save');

        $useCase = new SetUserAdminUseCase($userRepository, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute(targetUserId: 7, isAdmin: true, requestingUserId: 1);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('account_type', $result);
        $this->assertArrayHasKey('is_admin', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayNotHasKey('password_hash', $result);
    }
}
