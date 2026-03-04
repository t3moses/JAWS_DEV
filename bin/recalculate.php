#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron Recalculation Entry Point
 *
 * Runs the season update pipeline (selection + assignment) on event day,
 * within the 1-hour window after the blackout window closes.
 * Handles its own timing and idempotency so the cron schedule can run hourly
 * without producing duplicate runs.
 *
 * Usage:
 *   php bin/recalculate.php
 *
 * Cron schedule (hourly):
 *   0 * * * * /usr/bin/php /opt/bitnami/jaws/bin/recalculate.php >> /opt/bitnami/jaws/logs/cron.log 2>&1
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

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] recalculate.php\n";

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

$eventId   = EventId::fromString($nextEventIdString);
$eventData = $eventRepo->findById($eventId);

if ($eventData === null) {
    echo "Event data not found for {$nextEventIdString}. Exiting.\n";
    exit(0);
}

$eventDate = $eventData['event_date'];  // YYYY-MM-DD

$now = new \DateTimeImmutable('now', new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Toronto'));

// -----------------------------------------------------------------------
// Timing check — event day only, within [blackout_to, blackout_to + 1h]
// -----------------------------------------------------------------------
$todayDate = $now->format('Y-m-d');

if ($todayDate !== $eventDate) {
    echo "Not event day (today={$todayDate}, event={$eventDate}). Exiting.\n";
    exit(0);
}

/** @var \App\Application\Port\Repository\SeasonRepositoryInterface $seasonRepo */
$seasonRepo  = $container->get(\App\Application\Port\Repository\SeasonRepositoryInterface::class);
$config      = $seasonRepo->getConfig();
$blackoutTo  = $config['blackout_to'] ?? '18:00:00';  // HH:MM:SS

$currentTime    = $now->format('H:i:s');
$windowEnd      = date('H:i:s', strtotime($blackoutTo) + 3600);

if ($currentTime < $blackoutTo || $currentTime > $windowEnd) {
    echo "Not in recalculation window (now={$currentTime}, window={$blackoutTo}–{$windowEnd}). Exiting.\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Idempotency check — cron_notifications table
// -----------------------------------------------------------------------
$pdo = Connection::getInstance();

$checkStmt = $pdo->prepare('SELECT id FROM cron_notifications WHERE event_id = ? AND type = ?');
$checkStmt->execute([$eventId->toString(), 'recalculate']);

if ($checkStmt->fetch() !== false) {
    echo "Already ran recalculate for {$eventId->toString()}. Exiting.\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Run season update pipeline
// -----------------------------------------------------------------------
/** @var \App\Application\UseCase\Season\ProcessSeasonUpdateUseCase $useCase */
$useCase = $container->get(\App\Application\UseCase\Season\ProcessSeasonUpdateUseCase::class);
$result  = $useCase->execute();

$eventsProcessed   = $result['events_processed'] ?? 0;
$flotillasGenerated = $result['flotillas_generated'] ?? 0;

// -----------------------------------------------------------------------
// Record notification (INSERT OR IGNORE for race safety)
// -----------------------------------------------------------------------
$insertStmt = $pdo->prepare(
    'INSERT OR IGNORE INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
);
$insertStmt->execute([$eventId->toString(), 'recalculate', $eventsProcessed, 0]);

echo "Done. events_processed={$eventsProcessed} flotillas_generated={$flotillasGenerated}\n";
exit(0);
