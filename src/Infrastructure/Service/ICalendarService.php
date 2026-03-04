<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\CalendarServiceInterface;
use App\Domain\ValueObject\EventId;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;

/**
 * iCalendar Service
 *
 * Implements calendar file generation using eluceo/ical library.
 */
class ICalendarService implements CalendarServiceInterface
{
    private string $calendarPath;

    public function __construct(?string $calendarPath = null)
    {
        if ($calendarPath === null) {
            $projectRoot = dirname(__DIR__, 3);
            $this->calendarPath = $projectRoot . '/database/calendars';
        } else {
            $this->calendarPath = $calendarPath;
        }

        // Ensure calendar directory exists
        if (!is_dir($this->calendarPath)) {
            mkdir($this->calendarPath, 0755, true);
        }
    }

    public function generateEventCalendar(
        EventId $eventId,
        \DateTimeInterface $date,
        string $startTime,
        string $finishTime,
        string $location,
        string $description
    ): string {
        // Parse times - handle both HH:MM:SS and HH:MM formats
        $dateString = $date->format('Y-m-d');

        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateString . ' ' . $startTime);
        if ($start === false) {
            // Try without seconds
            $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateString . ' ' . $startTime);
            if ($start === false) {
                throw new \RuntimeException("Failed to parse start time: {$startTime}");
            }
        }

        $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateString . ' ' . $finishTime);
        if ($end === false) {
            // Try without seconds
            $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateString . ' ' . $finishTime);
            if ($end === false) {
                throw new \RuntimeException("Failed to parse finish time: {$finishTime}");
            }
        }

        $event = new Event();
        $event
            ->setSummary('Social Day Cruising - ' . $eventId->toString())
            ->setDescription($description)
            ->setOccurrence(new TimeSpan(new DateTime($start, false), new DateTime($end, false)))
            ->setLocation(new Location($location));

        $calendar = new Calendar([$event]);

        return $this->renderCalendar($calendar);
    }

    public function generateSeasonCalendar(array $events): string
    {
        $calendarEvents = [];

        foreach ($events as $eventData) {
            $start = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $eventData['date']->format('Y-m-d') . ' ' . $eventData['start_time']
            );
            $end = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $eventData['date']->format('Y-m-d') . ' ' . $eventData['finish_time']
            );

            $event = new Event();
            $event
                ->setSummary('Social Day Cruising - ' . $eventData['event_id'])
                ->setDescription($eventData['description'] ?? 'Social Day Cruising Event')
                ->setOccurrence(new TimeSpan(new DateTime($start, false), new DateTime($end, false)))
                ->setLocation(new Location($eventData['location'] ?? 'Nepean Sailing Club'));

            $calendarEvents[] = $event;
        }

        $calendar = new Calendar($calendarEvents);

        return $this->renderCalendar($calendar);
    }

    public function generateCrewCalendar(string $crewName, array $assignments): string
    {
        $calendarEvents = [];

        foreach ($assignments as $assignment) {
            $start = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $assignment['date']->format('Y-m-d') . ' ' . $assignment['start_time']
            );
            $end = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $assignment['date']->format('Y-m-d') . ' ' . $assignment['finish_time']
            );

            $boatName = $assignment['boat_name'] ?? 'TBD';

            $event = new Event();
            $event
                ->setSummary("Social Day Cruising - {$boatName}")
                ->setDescription("You are assigned to {$boatName} for this event.\n\nCrew: {$crewName}")
                ->setOccurrence(new TimeSpan(new DateTime($start, false), new DateTime($end, false)))
                ->setLocation(new Location($assignment['location'] ?? 'Nepean Sailing Club'));

            $calendarEvents[] = $event;
        }

        $calendar = new Calendar($calendarEvents);

        return $this->renderCalendar($calendar);
    }

    public function saveCalendarFile(string $content, string $filename): string
    {
        // Ensure .ics extension
        if (!str_ends_with($filename, '.ics')) {
            $filename .= '.ics';
        }

        $filepath = $this->calendarPath . '/' . $filename;
        file_put_contents($filepath, $content);

        return $filepath;
    }

    /**
     * Render calendar to iCalendar string
     */
    private function renderCalendar(Calendar $calendar): string
    {
        $factory = new CalendarFactory();
        $component = $factory->createCalendar($calendar);

        return $component->__toString();
    }
}
