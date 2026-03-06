<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\CrewNotFoundException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase;
use App\Domain\Entity\Crew;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use PHPUnit\Framework\TestCase;

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

    public function testThrowsCrewNotFoundWhenCrewMissing(): void
    {
        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->method('findByKey')->willReturn(null);

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo);

        $this->expectException(CrewNotFoundException::class);
        $useCase->execute('nonexistent-crew', 'some-boat');
    }

    public function testCallsRemoveFromWhitelistAndReloads(): void
    {
        $crew = $this->createCrew('test-crew', ['my-boat']);
        $crewAfterRemoval = $this->createCrew('test-crew'); // no whitelist

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

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo);

        $result = $useCase->execute('test-crew', 'my-boat');

        $this->assertNotContains('my-boat', $result['whitelist']);
    }

    public function testReturnedSummaryContainsExpectedFields(): void
    {
        $crew = $this->createCrew('test-crew', ['my-boat']);

        $crewRepo = $this->createMock(CrewRepositoryInterface::class);
        $crewRepo->method('findByKey')->willReturn($crew);
        $crewRepo->method('removeFromWhitelist');

        $useCase = new RemoveFromCrewWhitelistUseCase($crewRepo);

        $result = $useCase->execute('test-crew', 'my-boat');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('skill', $result);
        $this->assertArrayHasKey('partner_key', $result);
        $this->assertArrayHasKey('whitelist', $result);
    }
}
