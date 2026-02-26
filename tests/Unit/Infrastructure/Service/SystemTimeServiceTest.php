<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Service;

use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Domain\Enum\TimeSource;
use App\Infrastructure\Service\SystemTimeService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SystemTimeService
 *
 * Verifies:
 * - Production mode (default, no repo)
 * - Simulated mode (repo returns SIMULATED source + datetime)
 * - now() returns full simulated datetime including time-of-day
 * - today() always has a zeroed time component
 * - isInBlackoutWindow() boundary behaviour
 * - setTimeSource() updates in-memory state
 */
class SystemTimeServiceTest extends TestCase
{
    // ==================== Production Mode ====================

    public function testDefaultsToProductionModeWithoutRepo(): void
    {
        $service = new SystemTimeService();

        $this->assertEquals(TimeSource::PRODUCTION, $service->getTimeSource());
    }

    public function testNowReturnsCurrentTimeInProductionMode(): void
    {
        $before = new \DateTimeImmutable();
        $service = new SystemTimeService();
        $now = $service->now();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    public function testTodayHasZeroTimeComponentInProductionMode(): void
    {
        $service = new SystemTimeService();

        $this->assertEquals('00:00:00', $service->today()->format('H:i:s'));
    }

    public function testUsesProductionTimeWhenRepoReturnsProductionSource(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::PRODUCTION);
        $repo->method('getSimulatedDate')->willReturn(null);

        $service = new SystemTimeService($repo);

        $this->assertEquals(TimeSource::PRODUCTION, $service->getTimeSource());
    }

    // ==================== Simulated Mode ====================

    public function testNowReturnsFullSimulatedDatetime(): void
    {
        $simulatedDatetime = new \DateTimeImmutable('2026-05-15 14:30:00');

        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn($simulatedDatetime);

        $service = new SystemTimeService($repo);

        // now() must return the full simulated datetime — date AND time
        $this->assertEquals('2026-05-15 14:30:00', $service->now()->format('Y-m-d H:i:s'));
    }

    public function testTodayZeroesTimeInSimulatedMode(): void
    {
        $simulatedDatetime = new \DateTimeImmutable('2026-05-15 14:30:00');

        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn($simulatedDatetime);

        $service = new SystemTimeService($repo);
        $today = $service->today();

        $this->assertEquals('2026-05-15', $today->format('Y-m-d'));
        $this->assertEquals('00:00:00', $today->format('H:i:s'));
    }

    public function testNowDoesNotUseRealWallClockInSimulatedMode(): void
    {
        // If now() were grafting the real wall-clock time onto the simulated date
        // (the old bug), this test would flap whenever real time crosses a boundary.
        // With the fix, now() returns the stored datetime unchanged.
        $simulatedDatetime = new \DateTimeImmutable('2026-05-15 02:00:00');

        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn($simulatedDatetime);

        $service = new SystemTimeService($repo);

        // Time component must be exactly what was stored, not the real clock
        $this->assertEquals('02:00:00', $service->now()->format('H:i:s'));
    }

    // ==================== Blackout Window ====================

    public function testIsInBlackoutWindowReturnsTrueInsideWindow(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 14:00:00'));

        $service = new SystemTimeService($repo);

        $this->assertTrue($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowReturnsFalseBeforeWindow(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 09:00:00'));

        $service = new SystemTimeService($repo);

        $this->assertFalse($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowReturnsFalseAfterWindow(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 19:00:00'));

        $service = new SystemTimeService($repo);

        $this->assertFalse($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowAtStartBoundaryIsActive(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 10:00:00'));

        $service = new SystemTimeService($repo);

        $this->assertTrue($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowAtEndBoundaryIsActive(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 18:00:00'));

        $service = new SystemTimeService($repo);

        $this->assertTrue($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowOneSecondBeforeWindowIsInactive(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 09:59:59'));

        $service = new SystemTimeService($repo);

        $this->assertFalse($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    public function testIsInBlackoutWindowOneSecondAfterWindowIsInactive(): void
    {
        $repo = $this->createMock(SeasonRepositoryInterface::class);
        $repo->method('getTimeSource')->willReturn(TimeSource::SIMULATED);
        $repo->method('getSimulatedDate')->willReturn(new \DateTimeImmutable('2026-05-15 18:00:01'));

        $service = new SystemTimeService($repo);

        $this->assertFalse($service->isInBlackoutWindow('10:00:00', '18:00:00'));
    }

    // ==================== setTimeSource ====================

    public function testSetTimeSourceSimulatedWithoutDateThrowsException(): void
    {
        $service = new SystemTimeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->setTimeSource(TimeSource::SIMULATED, null);
    }

    public function testSetTimeSourceUpdatesInMemoryState(): void
    {
        $service = new SystemTimeService();

        $service->setTimeSource(TimeSource::SIMULATED, new \DateTimeImmutable('2026-05-15 14:00:00'));

        $this->assertEquals(TimeSource::SIMULATED, $service->getTimeSource());
        $this->assertEquals('2026-05-15 14:00:00', $service->now()->format('Y-m-d H:i:s'));
    }

    public function testSetTimeSourceBackToProductionClearsSimulatedDate(): void
    {
        $service = new SystemTimeService();
        $service->setTimeSource(TimeSource::SIMULATED, new \DateTimeImmutable('2026-05-15 14:00:00'));

        $service->setTimeSource(TimeSource::PRODUCTION);

        $this->assertEquals(TimeSource::PRODUCTION, $service->getTimeSource());
        // now() should no longer return the fixed simulated time
        $this->assertNotEquals('2026-05-15 14:00:00', $service->now()->format('Y-m-d H:i:s'));
    }
}
