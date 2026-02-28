#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron Notification Entry Point
 *
 * Dispatches crew reminder or crew list emails based on --type argument.
 * Handles its own timing logic so the cron schedule can run hourly
 * without producing duplicate sends.
 *
 * Usage:
 *   php bin/notify.php --type=reminder    # sends ~24h before event start
 *   php bin/notify.php --type=crew-list   # sends when blackout window opens on event day
 *
 * Cron schedule (hourly):
 *   0 * * * * /usr/bin/php /opt/bitnami/jaws/bin/notify.php --type=reminder   >> /opt/bitnami/jaws/logs/cron.log 2>&1
 *   0 * * * * /usr/bin/php /opt/bitnami/jaws/bin/notify.php --type=crew-list  >> /opt/bitnami/jaws/logs/cron.log 2>&1
 */

// Resolve project root regardless of where the script is called from
$projectRoot = dirname(__DIR__);

// Bootstrap autoloader and environment
require_once $projectRoot . '/vendor/autoload.php';

// Load .env if it exists (development convenience)
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

// Always pin DB_PATH to an absolute path based on the project root.
// The .env file may contain a relative path that only works from a specific CWD.
$absoluteDbPath = $projectRoot . '/database/jaws.db';
putenv('DB_PATH=' . $absoluteDbPath);

use App\Domain\ValueObject\EventId;
use App\Infrastructure\Persistence\SQLite\Connection;

// -----------------------------------------------------------------------
// Parse --type argument
// -----------------------------------------------------------------------
$options = getopt('', ['type:']);
$type = $options['type'] ?? null;

if (!in_array($type, ['reminder', 'crew-list'], true)) {
    fwrite(STDERR, "Usage: php bin/notify.php --type=reminder|crew-list\n");
    exit(1);
}

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] notify.php --type={$type}\n";

// -----------------------------------------------------------------------
// Bootstrap DI container (uses production DB)
// -----------------------------------------------------------------------
$container = require $projectRoot . '/config/container.php';

// -----------------------------------------------------------------------
// Find the next upcoming event
// -----------------------------------------------------------------------
/** @var \App\Application\Port\Repository\EventRepositoryInterface $eventRepo */
$eventRepo = $container->get(\App\Application\Port\Repository\EventRepositoryInterface::class);

$nextEventIdString = $eventRepo->findNextEvent();

if ($nextEventIdString === null) {
    echo "No upcoming events. Exiting.\n";
    exit(0);
}

$eventId = EventId::fromString($nextEventIdString);
$eventData = $eventRepo->findById($eventId);

if ($eventData === null) {
    echo "Event data not found for {$nextEventIdString}. Exiting.\n";
    exit(0);
}

$eventDate = $eventData['event_date'];   // YYYY-MM-DD
$startTime = $eventData['start_time'];   // HH:MM:SS

$now = new \DateTimeImmutable('now', new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Toronto'));

// -----------------------------------------------------------------------
// Timing check
// -----------------------------------------------------------------------
if ($type === 'reminder') {
    // Send when the event is 23–25 hours away (2h window tolerates hourly cron drift)
    $eventStart = new \DateTimeImmutable("{$eventDate} {$startTime}", new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Toronto'));
    $diffSeconds = $eventStart->getTimestamp() - $now->getTimestamp();
    $diffHours   = $diffSeconds / 3600.0;

    if ($diffHours < 23.0 || $diffHours > 25.0) {
        $diffFormatted = number_format($diffHours, 2);
        echo "Not in reminder window (diff={$diffFormatted}h). Exiting.\n";
        exit(0);
    }
} elseif ($type === 'crew-list') {
    // Send only on event day, within [blackout_from, blackout_from + 1h]
    $todayDate = $now->format('Y-m-d');

    if ($todayDate !== $eventDate) {
        echo "Not event day (today={$todayDate}, event={$eventDate}). Exiting.\n";
        exit(0);
    }

    /** @var \App\Application\Port\Repository\SeasonRepositoryInterface $seasonRepo */
    $seasonRepo = $container->get(\App\Application\Port\Repository\SeasonRepositoryInterface::class);
    $config     = $seasonRepo->getConfig();
    $blackoutFrom = $config['blackout_from'] ?? '10:00:00';  // HH:MM:SS

    $currentTime  = $now->format('H:i:s');
    $blackoutEnd  = date('H:i:s', strtotime($blackoutFrom) + 3600);

    if ($currentTime < $blackoutFrom || $currentTime > $blackoutEnd) {
        echo "Not in crew-list window (now={$currentTime}, window={$blackoutFrom}–{$blackoutEnd}). Exiting.\n";
        exit(0);
    }
}

// -----------------------------------------------------------------------
// Idempotency check — cron_notifications table
// -----------------------------------------------------------------------
$pdo = Connection::getInstance();

$checkStmt = $pdo->prepare('SELECT id FROM cron_notifications WHERE event_id = ? AND type = ?');
$checkStmt->execute([$eventId->toString(), $type]);

if ($checkStmt->fetch() !== false) {
    echo "Already sent {$type} for {$eventId->toString()}. Exiting.\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Dispatch to use case
// -----------------------------------------------------------------------
$recipientsCount = 0;
$skippedCount    = 0;

if ($type === 'reminder') {
    /** @var \App\Application\UseCase\Cron\SendCrewReminderUseCase $useCase */
    $useCase = $container->get(\App\Application\UseCase\Cron\SendCrewReminderUseCase::class);
    $result  = $useCase->execute($eventId);

    $recipientsCount = $result['sent'];
    $skippedCount    = $result['skipped'];

    foreach ($result['details'] as $line) {
        echo "  {$line}\n";
    }
} elseif ($type === 'crew-list') {
    /** @var \App\Application\UseCase\Cron\SendCrewListUseCase $useCase */
    $useCase = $container->get(\App\Application\UseCase\Cron\SendCrewListUseCase::class);
    $result  = $useCase->execute($eventId);

    $recipientsCount = $result['sent'] ? 1 : 0;
    $skippedCount    = $result['skipped'];

    foreach ($result['details'] as $line) {
        echo "  {$line}\n";
    }
}

// -----------------------------------------------------------------------
// Record notification (INSERT OR IGNORE for race safety)
// -----------------------------------------------------------------------
$insertStmt = $pdo->prepare(
    'INSERT OR IGNORE INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
);
$insertStmt->execute([$eventId->toString(), $type, $recipientsCount, $skippedCount]);

echo "Done. sent={$recipientsCount} skipped={$skippedCount}\n";
exit(0);
