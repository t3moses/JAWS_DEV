<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\UseCase\Admin\NotifyUserUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotifyUserUseCaseTest extends TestCase
{
    private function createUser(int $id, string $email = 'target@example.com'): User
    {
        $user = new User(
            email: $email,
            passwordHash: 'hashed_password',
            accountType: 'crew',
            isAdmin: false,
        );
        $user->setId($id);

        return $user;
    }

    private function makeUseCase(
        UserRepositoryInterface $userRepository,
        ?EmailServiceInterface $emailService = null,
    ): NotifyUserUseCase {
        return new NotifyUserUseCase(
            $userRepository,
            $emailService ?? $this->createMock(EmailServiceInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testThrowsValidationExceptionWhenSubjectOrMessageIsEmpty(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->never())->method('findById');

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(ValidationException::class);

        $useCase->execute(targetUserId: 5, subject: '  ', message: 'hello');
    }

    public function testThrowsRuntimeExceptionWhenUserNotFound(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(999)->willReturn(null);

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(\RuntimeException::class);

        $useCase->execute(targetUserId: 999, subject: 'Hi', message: 'hello');
    }

    public function testSendsEmailToTheUserAccountAddress(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(5)->willReturn($this->createUser(5, 'jane@example.com'));

        $emailService = $this->createMock(EmailServiceInterface::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'jane@example.com',
                'NSC Sail Friday',
                $this->stringContains('See you Saturday'),
            )
            ->willReturn(true);

        $useCase = $this->makeUseCase($userRepository, $emailService);

        $result = $useCase->execute(
            targetUserId: 5,
            subject: 'NSC Sail Friday',
            message: "Hello,\nSee you Saturday.",
        );

        $this->assertSame(1, $result['emails_sent']);
    }

    public function testReportsFailureWhenEmailServiceReturnsFalse(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(5)->willReturn($this->createUser(5));

        $emailService = $this->createMock(EmailServiceInterface::class);
        $emailService->method('send')->willReturn(false);

        $useCase = $this->makeUseCase($userRepository, $emailService);

        $result = $useCase->execute(targetUserId: 5, subject: 'Hi', message: 'hello');

        $this->assertSame(0, $result['emails_sent']);
    }
}
