<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Cron;

use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\UseCase\Cron\SendCrewReminderUseCase;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SendCrewReminderUseCaseTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;
    private CrewRepositoryInterface $crewRepository;
    private UserRepositoryInterface $userRepository;
    private EmailServiceInterface $emailService;
    private EmailTemplateServiceInterface $emailTemplateService;
    private SendCrewReminderUseCase $useCase;

    protected function setUp(): void
    {
        $this->eventRepository     = $this->createMock(EventRepositoryInterface::class);
        $this->crewRepository      = $this->createMock(CrewRepositoryInterface::class);
        $this->userRepository      = $this->createMock(UserRepositoryInterface::class);
        $this->emailService        = $this->createMock(EmailServiceInterface::class);
        $this->emailTemplateService = $this->createMock(EmailTemplateServiceInterface::class);

        $this->useCase = new SendCrewReminderUseCase(
            $this->eventRepository,
            $this->crewRepository,
            $this->userRepository,
            $this->emailService,
            $this->emailTemplateService,
            new NullLogger()
        );
    }

    private function makeEventData(): array
    {
        return [
            'event_id'    => 'Fri May 29',
            'event_date'  => '2026-05-29',
            'start_time'  => '12:45:00',
            'finish_time' => '17:00:00',
        ];
    }

    private function makeCrew(string $key = 'john-doe', ?int $userId = 1): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: null,
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: '12345',
            skill: SkillLevel::INTERMEDIATE,
            experience: null,
        );
        $crew->setUserId($userId);
        return $crew;
    }

    private function makeUser(int $id = 1, string $email = 'john@example.com'): User
    {
        $user = new User(
            email: $email,
            passwordHash: 'hash',
            accountType: 'crew',
            isAdmin: false
        );
        $user->setId($id);
        return $user;
    }

    public function testReturnsEmptyResultWhenEventNotFound(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $this->emailService->expects($this->never())->method('send');

        $result = $this->useCase->execute($eventId);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['skipped']);
        $this->assertNotEmpty($result['details']);
    }

    public function testReturnsEmptyResultWhenNoCrewAvailable(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([]);

        $this->emailService->expects($this->never())->method('send');

        $result = $this->useCase->execute($eventId);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testSkipsCrewWithNoUserId(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $crew    = $this->makeCrew('john-doe', null);  // No user_id

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([$crew]);

        $this->userRepository->expects($this->never())->method('findById');
        $this->emailService->expects($this->never())->method('send');

        $result = $this->useCase->execute($eventId);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('no linked user account', $result['details'][0]);
    }

    public function testSkipsCrewWhenUserNotFound(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $crew    = $this->makeCrew('john-doe', 99);

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([$crew]);
        $this->userRepository->method('findById')->with(99)->willReturn(null);

        $this->emailService->expects($this->never())->method('send');

        $result = $this->useCase->execute($eventId);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('user account not found', $result['details'][0]);
    }

    public function testSendsEmailToEachRegisteredCrew(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $crew1   = $this->makeCrew('john-doe', 1);
        $crew2   = $this->makeCrew('jane-smith', 2);
        $user1   = $this->makeUser(1, 'john@example.com');
        $user2   = $this->makeUser(2, 'jane@example.com');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([$crew1, $crew2]);

        $this->userRepository->method('findById')
            ->willReturnMap([[1, $user1], [2, $user2]]);

        $this->emailTemplateService->method('renderCrewReminderNotification')
            ->willReturn('<html>reminder</html>');

        $this->emailService->expects($this->exactly(2))
            ->method('send')
            ->willReturn(true);

        $result = $this->useCase->execute($eventId);

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testCountsFailedSendsAsSkipped(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $crew    = $this->makeCrew('john-doe', 1);
        $user    = $this->makeUser(1, 'john@example.com');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([$crew]);
        $this->userRepository->method('findById')->willReturn($user);
        $this->emailTemplateService->method('renderCrewReminderNotification')->willReturn('<html/>');
        $this->emailService->method('send')->willReturn(false);  // Send fails

        $result = $this->useCase->execute($eventId);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testReturnsCorrectResultShape(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->crewRepository->method('findAvailableForEvent')->willReturn([]);

        $result = $this->useCase->execute($eventId);

        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertIsInt($result['sent']);
        $this->assertIsInt($result['skipped']);
        $this->assertIsArray($result['details']);
    }
}
