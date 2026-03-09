<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RemoveFromCrewWhitelistUseCaseTest extends TestCase
{
    private function createCrew(string $key, array $whitelist = []): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: 'Test Crew',
            firstName: 'Test',
            lastName: 'Crew',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::NOVICE,
            experience: null,
        );
        $crew->setId(1);
        foreach ($whitelist as $boatKey) {
            $crew->addToWhitelist(BoatKey::fromString($boatKey));
        }
        return $crew;
    }

    private function createBoat(string $key): Boat
    {
        return new Boat(
            key: BoatKey::fromString($key),
            displayName: 'Test Boat',
            ownerFirstName: 'Owner',
            ownerLastName: 'Name',
            ownerMobile: null,
            minBerths: 2,
            maxBerths: 4,
            assistanceRequired: false,
            socialPreference: false,
        );
    }

    public function testThrowsCrewNotFoundWhenCrewMissing(): void
    {
        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->method('findByKey')->willReturn(null);

        $boatRepo = $this->createMock(BoatRepositoryInterface::class);
        $boatRepo->expects($this->never())->method('findByKey');

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo, $boatRepo, $this->createMock(LoggerInterface::class));

        $this->expectException(CrewNotFoundException::class);
        $useCase->execute('nonexistent-crew', 'some-boat');
    }

    public function testThrowsBoatNotFoundWhenBoatMissing(): void
    {
        $crew = $this->createCrew('test-crew', ['some-boat']);

        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->method('findByKey')->willReturn($crew);
        $crewRepo->expects($this->never())->method('removeFromWhitelist');

        $boatRepo = $this->createMock(BoatRepositoryInterface::class);
        $boatRepo->method('findByKey')->willReturn(null);

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo, $boatRepo, $this->createMock(LoggerInterface::class));

        $this->expectException(BoatNotFoundException::class);
        $useCase->execute('test-crew', 'nonexistent-boat');
    }

    public function testCallsRemoveFromWhitelistAndReloads(): void
    {
        $crew = $this->createCrew('test-crew', ['my-boat']);
        $crewAfterRemoval = $this->createCrew('test-crew'); // no whitelist
        $boat = $this->createBoat('my-boat');

        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->expects($this->exactly(2))
            ->method('findByKey')
            ->willReturnOnConsecutiveCalls($crew, $crewAfterRemoval);

        $crewRepo->expects($this->once())
            ->method('removeFromWhitelist')
            ->with(
                $this->callback(fn($k) => $k->toString() === 'test-crew'),
                $this->callback(fn($k) => $k->toString() === 'my-boat')
            );

        $boatRepo = $this->createMock(BoatRepositoryInterface::class);
        $boatRepo->method('findByKey')->willReturn($boat);

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo, $boatRepo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', 'my-boat');

        $this->assertNotContains('my-boat', $result['whitelist']);
    }

    public function testReturnedSummaryContainsExpectedFields(): void
    {
        $crew = $this->createCrew('test-crew', ['my-boat']);
        $boat = $this->createBoat('my-boat');

        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->method('findByKey')->willReturn($crew);
        $crewRepo->method('removeFromWhitelist');

        $boatRepo = $this->createMock(BoatRepositoryInterface::class);
        $boatRepo->method('findByKey')->willReturn($boat);

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo, $boatRepo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', 'my-boat');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('skill', $result);
        $this->assertArrayHasKey('partner_key', $result);
        $this->assertArrayHasKey('whitelist', $result);
    }
}
