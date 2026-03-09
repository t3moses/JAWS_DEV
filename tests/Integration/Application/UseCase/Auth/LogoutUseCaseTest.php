<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Auth;

use App\Application\UseCase\Auth\LogoutUseCase;
use App\Application\Exception\UserNotFoundException;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Domain\Entity\User;
use Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for LogoutUseCase
 *
 * Tests user logout functionality.
 * Note: JWT tokens are stateless; logout only updates the last_logout timestamp.
 * The client is responsible for deleting the token from local storage.
 */
class LogoutUseCaseTest extends IntegrationTestCase
{
    private LogoutUseCase $useCase;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->userRepository = new UserRepository();
        $this->useCase = new LogoutUseCase($this->userRepository, new NullLogger());
    }

    public function testLogoutUpdatesLastLogoutTimestamp(): void
    {
        // Create and save a user
        $user = new User(
            email: 'logout@example.com',
            passwordHash: password_hash('password123', PASSWORD_BCRYPT),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);
        $userId = $user->getId();

        // Verify initial state (no logout yet)
        $userBefore = $this->userRepository->findById($userId);
        $this->assertNull($userBefore->getLastLogout());

        // Execute logout
        sleep(1);  // Ensure timestamp difference
        $this->useCase->execute($userId);

        // Verify last_logout was updated
        $userAfter = $this->userRepository->findById($userId);
        $this->assertNotNull($userAfter->getLastLogout());
        $this->assertGreaterThan($userBefore->getLastLogin() ?? 0, $userAfter->getLastLogout()->getTimestamp());
    }

    public function testLogoutWithNonExistentUserThrowsException(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->useCase->execute(99999);
    }

    public function testMultipleLogoutsUpdateTimestampEachTime(): void
    {
        // Create and save a user
        $user = new User(
            email: 'multilogout@example.com',
            passwordHash: password_hash('password123', PASSWORD_BCRYPT),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);
        $userId = $user->getId();

        // First logout
        $this->useCase->execute($userId);
        $userAfterFirstLogout = $this->userRepository->findById($userId);
        $firstLogoutTime = $userAfterFirstLogout->getLastLogout()->getTimestamp();

        // Wait and second logout
        sleep(1);
        $this->useCase->execute($userId);
        $userAfterSecondLogout = $this->userRepository->findById($userId);
        $secondLogoutTime = $userAfterSecondLogout->getLastLogout()->getTimestamp();

        // Verify second logout timestamp is later
        $this->assertGreaterThan($firstLogoutTime, $secondLogoutTime);
    }

    public function testLogoutPreservesOtherUserData(): void
    {
        // Create user with login timestamp
        $user = new User(
            email: 'preserve@example.com',
            passwordHash: password_hash('password123', PASSWORD_BCRYPT),
            accountType: 'boat_owner',
            isAdmin: true
        );
        $this->userRepository->save($user);
        $user->updateLastLogin(new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->userRepository->save($user);
        
        $userId = $user->getId();
        $userBefore = $this->userRepository->findById($userId);

        // Logout
        $this->useCase->execute($userId);

        // Verify other data preserved
        $userAfter = $this->userRepository->findById($userId);
        $this->assertEquals($userBefore->getEmail(), $userAfter->getEmail());
        $this->assertEquals($userBefore->getAccountType(), $userAfter->getAccountType());
        $this->assertEquals($userBefore->isAdmin(), $userAfter->isAdmin());
        $this->assertEquals($userBefore->getLastLogin(), $userAfter->getLastLogin());
    }
}
