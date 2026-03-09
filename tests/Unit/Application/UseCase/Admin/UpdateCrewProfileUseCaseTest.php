<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Admin;

use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\UseCase\Admin\UpdateCrewProfileUseCase;
use App\Domain\Entity\Crew;
use App\Domain\Enum\SkillLevel;
use App\Domain\ValueObject\CrewKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateCrewProfileUseCaseTest extends TestCase
{
    private function createCrew(string $key, SkillLevel $skill = SkillLevel::NOVICE, ?string $partnerKey = null): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: 'Test Crew',
            firstName: 'Test',
            lastName: 'Crew',
            partnerKey: $partnerKey ? CrewKey::fromString($partnerKey) : null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: $skill,
            experience: null,
        );
        $crew->setId(1);
        return $crew;
    }

    public function testThrowsCrewNotFoundExceptionWhenCrewMissing(): void
    {
        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->expects($this->once())
            ->method('findByKey')
            ->willReturn(null);
        $repo->expects($this->never())->method('save');

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $this->expectException(CrewNotFoundException::class);
        $useCase->execute('nonexistent', null, null);
    }

    public function testThrowsValidationExceptionForInvalidSkill(): void
    {
        $crew = $this->createCrew('test-crew');

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($crew);
        $repo->expects($this->never())->method('save');

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $this->expectException(ValidationException::class);
        $useCase->execute('test-crew', 99, null);
    }

    public function testUpdatesSkillLevel(): void
    {
        $crew = $this->createCrew('test-crew', SkillLevel::NOVICE);

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($crew);
        $repo->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($c) => $c->getSkill() === SkillLevel::ADVANCED));

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', 2, null);

        $this->assertEquals(2, $result['skill']);
        $this->assertEquals(SkillLevel::ADVANCED, $crew->getSkill());
    }

    public function testThrowsValidationExceptionWhenPartnerNotFound(): void
    {
        $crew = $this->createCrew('test-crew');

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')
            ->willReturnCallback(function (CrewKey $key) use ($crew) {
                if ($key->toString() === 'test-crew') {
                    return $crew;
                }
                return null; // partner not found
            });
        $repo->expects($this->never())->method('save');

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $this->expectException(ValidationException::class);
        $useCase->execute('test-crew', null, 'nonexistent-partner');
    }

    public function testSetsPartnerKey(): void
    {
        $crew = $this->createCrew('test-crew');
        $partner = $this->createCrew('partner-crew');

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')
            ->willReturnCallback(function (CrewKey $key) use ($crew, $partner) {
                return match ($key->toString()) {
                    'test-crew' => $crew,
                    'partner-crew' => $partner,
                    default => null,
                };
            });
        $repo->expects($this->once())->method('save');

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', null, 'partner-crew');

        $this->assertEquals('partner-crew', $result['partner_key']);
    }

    public function testClearsPartnerKey(): void
    {
        $crew = $this->createCrew('test-crew', SkillLevel::NOVICE, 'old-partner');

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($crew);
        $repo->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($c) => $c->getPartnerKey() === null));

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', null, null, clearPartner: true);

        $this->assertNull($result['partner_key']);
    }

    public function testReturnedSummaryContainsExpectedFields(): void
    {
        $crew = $this->createCrew('test-crew');

        $repo = $this->createMock(CrewRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($crew);
        $repo->method('save');

        $useCase = new UpdateCrewProfileUseCase($repo, $this->createMock(LoggerInterface::class));

        $result = $useCase->execute('test-crew', 1, null);

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('skill', $result);
        $this->assertArrayHasKey('partner_key', $result);
        $this->assertArrayHasKey('whitelist', $result);
    }
}
