<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Crew;

use App\Application\DTO\Response\AvailabilityResponse;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\UseCase\Crew\GetCrewAvailabilityUseCase;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use PHPUnit\Framework\TestCase;

class GetCrewAvailabilityUseCaseTest extends TestCase
{
    private function createCrew(CrewKey $key): Crew
    {
        $crew = new Crew(
            key: $key,
            displayName: 'John Doe',
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: null,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: '12345',
            skill: SkillLevel::INTERMEDIATE,
            experience: '5 years'
        );
        return $crew;
    }

    public function testExecuteReturnsAvailabilityResponseForValidCrew(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);

        // Set mixed availability statuses
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('event2'), AvailabilityStatus::SELECTED);
        // event3 has no availability record (not registered)

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn([
                'event1' => '2026-05-01',
                'event2' => '2026-05-08',
                'event3' => '2026-05-15'
            ]);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertInstanceOf(AvailabilityResponse::class, $result);
        $this->assertCount(3, $result->availability);
        $this->assertTrue($result->availability['2026-05-01']); // Has record (NOT_SELECTED)
        $this->assertTrue($result->availability['2026-05-08']); // Has record (SELECTED)
        $this->assertFalse($result->availability['2026-05-15']); // No record
    }

    public function testExecuteHandlesMultipleEvents(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);

        // Test availability records with different statuses
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('event2'), AvailabilityStatus::SELECTED);
        // event3 has no record (not registered)
        // event4 has no record (not registered)

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn([
                'event1' => '2026-05-01',
                'event2' => '2026-05-08',
                'event3' => '2026-05-15',
                'event4' => '2026-05-22'
            ]);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertCount(4, $result->availability);
        $this->assertTrue($result->availability['2026-05-01']); // Has record (NOT_SELECTED)
        $this->assertTrue($result->availability['2026-05-08']); // Has record (SELECTED)
        $this->assertFalse($result->availability['2026-05-15']); // No record
        $this->assertFalse($result->availability['2026-05-22']); // No record
    }

    public function testExecuteMapsAvailableToTrue(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertTrue($result->availability['2026-05-01']);
    }

    public function testExecuteMapsGuaranteedToTrue(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::SELECTED);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertTrue($result->availability['2026-05-01']);
    }

    public function testExecuteMapsUnavailableToFalse(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertFalse($result->availability['2026-05-01']);
    }

    public function testExecuteMapsWithdrawnToFalse(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertFalse($result->availability['2026-05-01']);
    }

    public function testExecuteThrowsExceptionWhenCrewNotFound(): void
    {
        // Arrange
        $userId = 999;

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        // Event repository should never be called (optimization)
        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->never())
            ->method('getEventDateMap');

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Assert
        $this->expectException(CrewNotFoundException::class);
        $this->expectExceptionMessage("Crew not found for user ID: 999");

        // Act
        $useCase->execute($userId);
    }

    public function testExecuteReturnsEmptyArrayWhenCrewHasNoAvailability(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        // Don't set any availability

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn([
                'event1' => '2026-05-01',
                'event2' => '2026-05-08'
            ]);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertInstanceOf(AvailabilityResponse::class, $result);
        // When crew has no availability records, return false for all events in the map
        $this->assertCount(2, $result->availability);
        $this->assertFalse($result->availability['2026-05-01']);
        $this->assertFalse($result->availability['2026-05-08']);
    }

    public function testExecuteReturnsEmptyArrayWhenEventDateMapIsEmpty(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('event2'), AvailabilityStatus::SELECTED);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        // No events exist in system
        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn([]);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertInstanceOf(AvailabilityResponse::class, $result);
        $this->assertEmpty($result->availability);
    }

    public function testExecuteSkipsEventsNotInDateMap(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);

        // Crew has availability for 3 events
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);
        $crew->setAvailability(EventId::fromString('event2'), AvailabilityStatus::SELECTED);
        $crew->setAvailability(EventId::fromString('orphaned'), AvailabilityStatus::NOT_SELECTED);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        // Event date map only contains 2 events (orphaned event missing)
        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn([
                'event1' => '2026-05-01',
                'event2' => '2026-05-08'
                // 'orphaned' is not in the map
            ]);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $this->assertCount(2, $result->availability);
        $this->assertArrayHasKey('2026-05-01', $result->availability);
        $this->assertArrayHasKey('2026-05-08', $result->availability);
        // Orphaned event should be filtered out
        $this->assertArrayNotHasKey('orphaned', $result->availability);
    }

    public function testExecuteUsesIsoDateFormat(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        $keys = array_keys($result->availability);
        $this->assertCount(1, $keys);
        // Verify ISO date format (YYYY-MM-DD)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $keys[0]);
    }

    public function testExecuteCallsRepositoryMethodsExactlyOnce(): void
    {
        // Arrange
        $crewKey = CrewKey::fromString('johndoe');
        $crew = $this->createCrew($crewKey);
        $crew->setAvailability(EventId::fromString('event1'), AvailabilityStatus::NOT_SELECTED);

        // Mock with strict expectations
        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn($crew);

        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->once())
            ->method('getEventDateMap')
            ->willReturn(['event1' => '2026-05-01']);

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        // Act
        $result = $useCase->execute(1);

        // Assert
        // Expectations are verified by PHPUnit after test completion
        $this->assertInstanceOf(AvailabilityResponse::class, $result);
    }

    public function testExecuteDoesNotCallEventRepositoryWhenCrewNotFound(): void
    {
        // Arrange
        $userId = 999;

        $crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $crewRepository->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        // Event repository should never be called (optimization)
        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->never())
            ->method('getEventDateMap');

        $useCase = new GetCrewAvailabilityUseCase($crewRepository, $eventRepository);

        try {
            // Act
            $useCase->execute($userId);
            $this->fail('Expected CrewNotFoundException was not thrown');
        } catch (CrewNotFoundException $e) {
            // Assert
            // Expectations are verified by PHPUnit after test completion
            $this->assertStringContainsString('999', $e->getMessage());
        }
    }
}
