<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\TimeServiceInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Domain\Enum\TimeSource;

/**
 * System Time Service
 *
 * Implements time operations with support for simulated time (testing).
 */
class SystemTimeService implements TimeServiceInterface
{
    private TimeSource $timeSource;
    private ?\DateTimeImmutable $simulatedDate = null;
    private ?SeasonRepositoryInterface $seasonRepo = null;

    public function __construct(?SeasonRepositoryInterface $seasonRepo = null)
    {
        $this->seasonRepo = $seasonRepo;
        $this->timeSource = TimeSource::PRODUCTION;

        // Load time source from repository if available
        if ($this->seasonRepo !== null) {
            $this->timeSource = $this->seasonRepo->getTimeSource();
            $rawDate = $this->seasonRepo->getSimulatedDate();
            $this->simulatedDate = $rawDate !== null ? \DateTimeImmutable::createFromInterface($rawDate) : null;
        }
    }

    public function now(): \DateTimeImmutable
    {
        if ($this->timeSource === TimeSource::SIMULATED && $this->simulatedDate !== null) {
            return $this->simulatedDate;
        }

        return new \DateTimeImmutable();
    }

    public function today(): \DateTimeImmutable
    {
        if ($this->timeSource === TimeSource::SIMULATED && $this->simulatedDate !== null) {
            return $this->simulatedDate->setTime(0, 0, 0);
        }

        return new \DateTimeImmutable('today');
    }

    public function getTimeSource(): TimeSource
    {
        return $this->timeSource;
    }

    public function setTimeSource(TimeSource $source, ?\DateTimeInterface $simulatedDate = null): void
    {
        $this->timeSource = $source;

        if ($source === TimeSource::SIMULATED) {
            if ($simulatedDate === null) {
                throw new \InvalidArgumentException('Simulated date is required when time source is SIMULATED');
            }
            $this->simulatedDate = \DateTimeImmutable::createFromInterface($simulatedDate);
        } else {
            $this->simulatedDate = null;
        }

        // Persist to repository if available
        if ($this->seasonRepo !== null) {
            $this->seasonRepo->setTimeSource($source, $simulatedDate);
        }
    }

    public function isInBlackoutWindow(string $blackoutFrom, string $blackoutTo): bool
    {
        $now = $this->now();
        $currentTime = $now->format('H:i:s');

        return $currentTime >= $blackoutFrom && $currentTime <= $blackoutTo;
    }

    public function parseTime(string $timeString): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('H:i:s', $timeString);

        if ($time === false) {
            throw new \InvalidArgumentException("Invalid time string: {$timeString}");
        }

        return $time;
    }

    public function format(\DateTimeInterface $dateTime, string $format = 'Y-m-d H:i:s'): string
    {
        return $dateTime->format($format);
    }
}
