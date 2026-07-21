<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\TransactionServiceInterface;
use App\Application\UseCase\Admin\DeleteUserUseCase;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteUserUseCaseTest extends TestCase
{
    private function createUser(int $id): User
    {
        $user = new User(
            email: "user{$id}@example.com",
            passwordHash: 'hashed_password',
            accountType: 'crew',
            isAdmin: false,
        );
        $user->setId($id);

        return $user;
    }

    private function makeUseCase(
        UserRepositoryInterface $userRepository,
        ?CrewRepositoryInterface $crewRepository = null,
        ?BoatRepositoryInterface $boatRepository = null,
        ?PasswordResetTokenRepositoryInterface $tokenRepository = null,
        ?TransactionServiceInterface $transactionService = null,
    ): DeleteUserUseCase {
        return new DeleteUserUseCase(
            $userRepository,
            $crewRepository ?? $this->createMock(CrewRepositoryInterface::class),
            $boatRepository ?? $this->createMock(BoatRepositoryInterface::class),
            $tokenRepository ?? $this->createMock(PasswordResetTokenRepositoryInterface::class),
            $transactionService ?? $this->createMock(TransactionServiceInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testThrowsValidationExceptionWhenTargetingOwnAccount(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->never())->method('findById');
        $userRepository->expects($this->never())->method('delete');

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(ValidationException::class);

        $useCase->execute(targetUserId: 42, requestingUserId: 42);
    }

    public function testThrowsRuntimeExceptionWhenUserNotFound(): void
    {
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $userRepository->expects($this->never())->method('delete');

        $useCase = $this->makeUseCase($userRepository);

        $this->expectException(\RuntimeException::class);

        $useCase->execute(targetUserId: 999, requestingUserId: 1);
    }

    public function testDeletesCrewProfileAndUser(): void
    {
        $targetUser = $this->createUser(5);
        $crew = new Crew(
            key: CrewKey::fromString('janedoe'),
            displayName: 'Jane D.',
            firstName: 'Jane',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::NOVICE,
            experience: null,
        );

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(5)->willReturn($targetUser);
        $userRepository->expects($this->once())->method('delete')->with(5);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->method('findByUserId')->with(5)->willReturn($crew);
        $crewRepository->expects($this->once())->method('delete')->with($crew->getKey());

        $boatRepository = $this->createMock(BoatRepositoryInterface::class);
        $boatRepository->method('findByOwnerUserId')->with(5)->willReturn(null);
        $boatRepository->expects($this->never())->method('delete');

        $tokenRepository = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokenRepository->expects($this->once())->method('deleteByUserId')->with(5);

        $transactionService = $this->createMock(TransactionServiceInterface::class);
        $transactionService->expects($this->once())->method('begin');
        $transactionService->expects($this->once())->method('commit');
        $transactionService->expects($this->never())->method('rollBack');

        $useCase = $this->makeUseCase(
            $userRepository,
            $crewRepository,
            $boatRepository,
            $tokenRepository,
            $transactionService,
        );

        $useCase->execute(targetUserId: 5, requestingUserId: 1);
    }

    public function testDeletesBoatProfileAndUser(): void
    {
        $targetUser = $this->createUser(6);
        $boat = new Boat(
            key: BoatKey::fromBoatName('Sailaway'),
            displayName: 'Sailaway',
            ownerFirstName: 'John',
            ownerLastName: 'Smith',
            ownerMobile: null,
            minBerths: 1,
            maxBerths: 4,
            assistanceRequired: false,
            socialPreference: false,
        );

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(6)->willReturn($targetUser);
        $userRepository->expects($this->once())->method('delete')->with(6);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->method('findByUserId')->with(6)->willReturn(null);
        $crewRepository->expects($this->never())->method('delete');

        $boatRepository = $this->createMock(BoatRepositoryInterface::class);
        $boatRepository->method('findByOwnerUserId')->with(6)->willReturn($boat);
        $boatRepository->expects($this->once())->method('delete')->with($boat->getKey());

        $useCase = $this->makeUseCase($userRepository, $crewRepository, $boatRepository);

        $useCase->execute(targetUserId: 6, requestingUserId: 1);
    }

    public function testRollsBackTransactionOnFailure(): void
    {
        $targetUser = $this->createUser(7);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('findById')->with(7)->willReturn($targetUser);
        $userRepository->method('delete')->willThrowException(new \RuntimeException('db error'));

        $transactionService = $this->createMock(TransactionServiceInterface::class);
        $transactionService->expects($this->once())->method('begin');
        $transactionService->expects($this->never())->method('commit');
        $transactionService->expects($this->once())->method('rollBack');

        $useCase = $this->makeUseCase($userRepository, transactionService: $transactionService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db error');

        $useCase->execute(targetUserId: 7, requestingUserId: 1);
    }
}
