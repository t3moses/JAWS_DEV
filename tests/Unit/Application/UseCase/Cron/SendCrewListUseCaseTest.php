<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Cron;

use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\UseCase\Cron\SendCrewListUseCase;
use App\Domain\Entity\User;
use App\Domain\ValueObject\EventId;
use PHPUnit\Framework\TestCase;

class SendCrewListUseCaseTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;
    private SeasonRepositoryInterface $seasonRepository;
    private UserRepositoryInterface $userRepository;
    private EmailServiceInterface $emailService;
    private EmailTemplateServiceInterface $emailTemplateService;
    private SendCrewListUseCase $useCase;

    protected function setUp(): void
    {
        $this->eventRepository      = $this->createMock(EventRepositoryInterface::class);
        $this->seasonRepository     = $this->createMock(SeasonRepositoryInterface::class);
        $this->userRepository       = $this->createMock(UserRepositoryInterface::class);
        $this->emailService         = $this->createMock(EmailServiceInterface::class);
        $this->emailTemplateService = $this->createMock(EmailTemplateServiceInterface::class);

        $this->useCase = new SendCrewListUseCase(
            $this->eventRepository,
            $this->seasonRepository,
            $this->userRepository,
            $this->emailService,
            $this->emailTemplateService,
            'admin@nsc.ca'
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

    private function makeFlotilla(array $crewedBoats = []): array
    {
        return [
            'crewed_boats'  => $crewedBoats,
            'waitlist_boats' => [],
            'waitlist_crews' => [],
        ];
    }

    private function makeCrewedBoat(string $boatName, int $ownerUserId = 1, array $crews = []): array
    {
        return [
            'boat' => [
                'display_name'  => $boatName,
                'owner_user_id' => $ownerUserId,
            ],
            'crews' => $crews,
        ];
    }

    private function makeUser(int $id = 1, string $email = 'owner@example.com'): User
    {
        $user = new User(
            email: $email,
            passwordHash: 'hash',
            accountType: 'boat_owner',
            isAdmin: false
        );
        $user->setId($id);
        return $user;
    }

    public function testReturnsEarlyWhenEventNotFound(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn(null);
        $this->emailService->expects($this->never())->method('sendWithCc');

        $result = $this->useCase->execute($eventId);

        $this->assertFalse($result['sent']);
        $this->assertSame(0, $result['cc_count']);
    }

    public function testReturnsEarlyWhenFlotillaNull(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn(null);
        $this->emailService->expects($this->never())->method('sendWithCc');

        $result = $this->useCase->execute($eventId);

        $this->assertFalse($result['sent']);
    }

    public function testReturnsEarlyWhenNoCrewedBoats(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([]));
        $this->emailService->expects($this->never())->method('sendWithCc');

        $result = $this->useCase->execute($eventId);

        $this->assertFalse($result['sent']);
    }

    public function testSkipsBoatWithNoOwnerUserId(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $boat    = $this->makeCrewedBoat('Sailaway', 0);  // owner_user_id = 0 → no account

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([$boat]));
        $this->emailTemplateService->method('renderCrewListNotification')->willReturn('<html/>');
        $this->emailService->method('sendWithCc')->willReturn(true);

        $result = $this->useCase->execute($eventId);

        $this->assertSame(1, $result['skipped']);
        // Email still sent, just no CC for this owner
        $this->assertSame(0, $result['cc_count']);
    }

    public function testSkipsOwnerWhenUserNotFound(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $boat    = $this->makeCrewedBoat('Sailaway', 99);

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([$boat]));
        $this->userRepository->method('findById')->with(99)->willReturn(null);
        $this->emailTemplateService->method('renderCrewListNotification')->willReturn('<html/>');
        $this->emailService->method('sendWithCc')->willReturn(true);

        $result = $this->useCase->execute($eventId);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['cc_count']);
    }

    public function testSendsEmailWithCcToOwners(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $boat1   = $this->makeCrewedBoat('Sailaway', 1);
        $boat2   = $this->makeCrewedBoat('Windward', 2);
        $user1   = $this->makeUser(1, 'owner1@example.com');
        $user2   = $this->makeUser(2, 'owner2@example.com');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([$boat1, $boat2]));
        $this->userRepository->method('findById')
            ->willReturnMap([[1, $user1], [2, $user2]]);
        $this->emailTemplateService->method('renderCrewListNotification')->willReturn('<html/>');

        $this->emailService->expects($this->once())
            ->method('sendWithCc')
            ->with(
                'admin@nsc.ca',
                $this->containsEqual('owner1@example.com'),
                $this->stringContains('Fri May 29'),
                '<html/>'
            )
            ->willReturn(true);

        $result = $this->useCase->execute($eventId);

        $this->assertTrue($result['sent']);
        $this->assertSame(2, $result['cc_count']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testDeduplicatesCcEmails(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        // Same owner on two boats
        $boat1 = $this->makeCrewedBoat('Boat A', 1);
        $boat2 = $this->makeCrewedBoat('Boat B', 1);
        $user  = $this->makeUser(1, 'owner@example.com');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([$boat1, $boat2]));
        $this->userRepository->method('findById')->willReturn($user);
        $this->emailTemplateService->method('renderCrewListNotification')->willReturn('<html/>');

        $this->emailService->expects($this->once())
            ->method('sendWithCc')
            ->with('admin@nsc.ca', ['owner@example.com'], $this->anything(), $this->anything())
            ->willReturn(true);

        $result = $this->useCase->execute($eventId);

        $this->assertSame(1, $result['cc_count']);
    }

    public function testReturnsFalseWhenEmailFails(): void
    {
        $eventId = EventId::fromString('Fri May 29');
        $boat    = $this->makeCrewedBoat('Sailaway', 1);
        $user    = $this->makeUser(1, 'owner@example.com');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn($this->makeFlotilla([$boat]));
        $this->userRepository->method('findById')->willReturn($user);
        $this->emailTemplateService->method('renderCrewListNotification')->willReturn('<html/>');
        $this->emailService->method('sendWithCc')->willReturn(false);

        $result = $this->useCase->execute($eventId);

        $this->assertFalse($result['sent']);
    }

    public function testReturnsCorrectResultShape(): void
    {
        $eventId = EventId::fromString('Fri May 29');

        $this->eventRepository->method('findById')->willReturn($this->makeEventData());
        $this->seasonRepository->method('getFlotilla')->willReturn(null);

        $result = $this->useCase->execute($eventId);

        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('cc_count', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertIsBool($result['sent']);
        $this->assertIsInt($result['cc_count']);
        $this->assertIsInt($result['skipped']);
        $this->assertIsArray($result['details']);
    }
}
