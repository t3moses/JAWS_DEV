<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\UseCase\Admin\SetUserStatusUseCase;
use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SetUserStatusUseCaseTest extends TestCase
{
    private function createUser(int $id, bool $disabled = false): User
    {
        $user = new User(
            email: "user{$id}@example.com",
            passwordHash: 'hashed_password',
            accountType: 'crew',
            isAdmin: false,
            disabledAt: $disabled ? new \DateTimeImmutable('2026-06-01 12:00:00') : null,
        );
        $user->setId($id);

        return $user;
    }

    private function makeUseCase(UserRepositoryInterface $userRepository): SetUserStatusUseCase
    {
        $season = $this->createMock(ProcessSeasonUpdateUseCase::class);
        $season->method('execute')->willReturn(['success' => true]);

        return new SetUserStatusUseCase(
            $userRepository,
            $season,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testThrowsValidationExceptionWhenTargetingOwnAccount(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->never())->method('findById');
        $userRepository->expects($this->never())->method('save');

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(ValidationException::class);

        $useCase->execute(targetUserId: 42, disabled: true, requestingUserId: 42);
    }

    public function testThrowsRuntimeExceptionWhenUserNotFound(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $userRepository->expects($this->never())->method('save');

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(\RuntimeException::class);

        $useCase->execute(targetUserId: 999, disabled: true, requestingUserId: 1);
    }

    public function testDisablesAndPersists(): void
    {
        $targetUser = $this->createUser(id: 5, disabled: false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(5)->willReturn($targetUser);
        $userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($u) => $u->isDisabled() === true));

        $useCase = $this->makeUseCase($userRepository);

        $result = $useCase->execute(targetUserId: 5, disabled: true, requestingUserId: 1);

        $this->assertTrue($targetUser->isDisabled());
        $this->assertTrue($result['disabled']);
        $this->assertNotNull($result['disabled_at']);
    }

    public function testReactivatesAndPersists(): void
    {
        $targetUser = $this->createUser(id: 5, disabled: true);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(5)->willReturn($targetUser);
        $userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($u) => $u->isDisabled() === false));

        $useCase = $this->makeUseCase($userRepository);

        $result = $useCase->execute(targetUserId: 5, disabled: false, requestingUserId: 1);

        $this->assertFalse($targetUser->isDisabled());
        $this->assertFalse($result['disabled']);
        $this->assertNull($result['disabled_at']);
    }

    public function testReRunsSeasonPipelineAfterChange(): void
    {
        $targetUser = $this->createUser(id: 8, disabled: false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->willReturn($targetUser);
        $userRepository->method('save');

        $season = $this->createMock(ProcessSeasonUpdateUseCase::class);
        $season->expects($this->once())
            ->method('execute')
            ->willReturn(['success' => true, 'events_processed' => 2]);

        $useCase = new SetUserStatusUseCase(
            $userRepository,
            $season,
            $this->createMock(LoggerInterface::class)
        );

        $result = $useCase->execute(targetUserId: 8, disabled: true, requestingUserId: 1);

        $this->assertSame(['success' => true, 'events_processed' => 2], $result['recalculation']);
    }

    public function testReturnedSummaryOmitsPasswordHash(): void
    {
        $targetUser = $this->createUser(id: 7, disabled: false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->willReturn($targetUser);
        $userRepository->method('save');

        $useCase = $this->makeUseCase($userRepository);

        $result = $useCase->execute(targetUserId: 7, disabled: true, requestingUserId: 1);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('disabled', $result);
        $this->assertArrayNotHasKey('password_hash', $result);
    }
}
