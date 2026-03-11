#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * JAWS Activity Digest
 *
 * Reads logs/app.log and prints a human-readable activity summary.
 *
 * Usage:
 *   php bin/digest.php              # last 24 hours (default)
 *   php bin/digest.php --since=24h  # last 24 hours
 *   php bin/digest.php --since=7d   # last 7 days
 *   php bin/digest.php --since=2h   # last 2 hours
 */

$projectRoot = dirname(__DIR__);
$logsDir     = $projectRoot . '/logs';

// -----------------------------------------------------------------------
// Parse --since argument
// -----------------------------------------------------------------------
$options = getopt('', ['since:']);
$since   = $options['since'] ?? '24h';

if (preg_match('/^(\d+)(h|d)$/', $since, $m)) {
    $amount  = (int) $m[1];
    $seconds = $m[2] === 'd' ? $amount * 86400 : $amount * 3600;
} else {
    fwrite(STDERR, "Invalid --since value '{$since}'. Use e.g. 24h or 7d.\n");
    exit(1);
}

$cutoff = time() - $seconds;

// -----------------------------------------------------------------------
// Resolve log files covering the requested window
// -----------------------------------------------------------------------
// Monolog RotatingFileHandler writes app-YYYY-MM-DD.log; dev uses app.log.
$logFiles = [];

// Collect all dated rotating files whose date >= cutoff day
foreach (glob($logsDir . '/app-*.log') as $path) {
    $basename = basename($path, '.log'); // e.g. "app-2026-03-08"
    if (preg_match('/^app-(\d{4}-\d{2}-\d{2})$/', $basename, $dm)) {
        if (strtotime($dm[1]) >= strtotime(date('Y-m-d', $cutoff))) {
            $logFiles[] = $path;
        }
    }
}
sort($logFiles); // chronological order

// Fall back to plain app.log (dev environment)
if (empty($logFiles) && file_exists($logsDir . '/app.log')) {
    $logFiles[] = $logsDir . '/app.log';
}

if (empty($logFiles)) {
    fwrite(STDERR, "No log files found in {$logsDir}\n");
    exit(1);
}

// -----------------------------------------------------------------------
// Read and filter log entries
// -----------------------------------------------------------------------
$entries = [];
foreach ($logFiles as $logFile) {
    $handle = fopen($logFile, 'r');
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $entry = json_decode($line, true);
        if ($entry === null) {
            continue;
        }
        $ts = strtotime($entry['datetime'] ?? '');
        if ($ts === false || $ts < $cutoff) {
            continue;
        }
        $entries[] = $entry;
    }
    fclose($handle);
}

// -----------------------------------------------------------------------
// Bucket entries by message type
// -----------------------------------------------------------------------
$registrations      = [];
$logins             = [];
$authFailures       = [];
$crewAvailability   = [];
$boatAvailability   = [];
$assignments        = [];
$emailsSent         = [];
$adminActions       = [];

foreach ($entries as $e) {
    $msg = $e['message'] ?? '';
    $ctx = $e['context'] ?? [];

    switch ($msg) {
        case 'auth.registered':
            $registrations[] = $ctx;
            break;
        case 'auth.login':
            $logins[] = $ctx;
            break;
        case 'http.auth_failure':
            $authFailures[] = $ctx + ['datetime' => $e['datetime']];
            break;
        case 'crew.availability_updated':
            $crewAvailability[] = $ctx;
            break;
        case 'boat.availability_updated':
            $boatAvailability[] = $ctx;
            break;
        case 'season_update.assignment_complete':
            $assignments[] = $ctx + ['datetime' => $e['datetime']];
            break;
        case 'email.sent':
            $emailsSent[] = $ctx;
            break;
        default:
            if (str_starts_with($msg, 'admin.')) {
                $adminActions[] = $e;
            }
            break;
    }
}

// -----------------------------------------------------------------------
// Format header
// -----------------------------------------------------------------------
$sinceLabel  = $m[2] === 'd' ? "{$amount} day" . ($amount !== 1 ? 's' : '') : "{$amount} hour" . ($amount !== 1 ? 's' : '');
$sinceTime   = date('Y-m-d H:i', $cutoff);
$header      = "JAWS Activity Digest — Last {$sinceLabel} (since {$sinceTime})";
$separator   = str_repeat('=', strlen($header));

echo "\n{$header}\n{$separator}\n";

// -----------------------------------------------------------------------
// REGISTRATIONS
// -----------------------------------------------------------------------
$count = count($registrations);
echo "\nREGISTRATIONS ({$count})\n";
if ($count === 0) {
    echo "  (none)\n";
} else {
    foreach ($registrations as $r) {
        $userId = $r['user_id'] ?? '?';
        $email  = $r['email']   ?? '?';
        $type   = $r['account_type'] ?? '?';
        echo "  - user#{$userId} {$email} ({$type})\n";
    }
}

// -----------------------------------------------------------------------
// LOGINS
// -----------------------------------------------------------------------
$loginCount   = count($logins);
$failureCount = count($authFailures);
echo "\nLOGINS ({$loginCount} successful, {$failureCount} failed)\n";
foreach ($authFailures as $f) {
    $time = date('H:i', strtotime($f['datetime']));
    $ip   = $f['ip'] ?? $f['remote_addr'] ?? null;
    $note = $ip ? " from {$ip}" : '';
    echo "  ! Failed login attempt at {$time}{$note}\n";
}
if ($loginCount === 0 && $failureCount === 0) {
    echo "  (none)\n";
}

// -----------------------------------------------------------------------
// AVAILABILITY UPDATES
// -----------------------------------------------------------------------
$crewCount = count($crewAvailability);
$boatCount = count($boatAvailability);
echo "\nAVAILABILITY UPDATES ({$crewCount} crew, {$boatCount} boat)\n";
if ($crewCount === 0 && $boatCount === 0) {
    echo "  (none)\n";
}

// -----------------------------------------------------------------------
// NEXT EVENT ASSIGNMENT
// -----------------------------------------------------------------------
echo "\nNEXT EVENT ASSIGNMENT\n";
if (empty($assignments)) {
    echo "  (no pipeline runs in period)\n";
} else {
    // Show the most recent assignment_complete
    $latest = end($assignments);
    $eventId     = $latest['event_id']          ?? '?';
    $crewedBoats = $latest['crewed_boats_count'] ?? '?';
    echo "  Event: {$eventId}\n";
    echo "  {$crewedBoats} boat" . ($crewedBoats !== 1 ? 's' : '') . " crewed\n";
}

// -----------------------------------------------------------------------
// EMAILS SENT
// -----------------------------------------------------------------------
echo "\nEMAILS SENT\n";
if (empty($emailsSent)) {
    echo "  (none)\n";
} else {
    $byType = [];
    foreach ($emailsSent as $e) {
        $type = $e['type'] ?? 'unknown';
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }
    $parts = [];
    foreach ($byType as $type => $cnt) {
        $parts[] = $cnt === 1 ? $type : "{$type} x{$cnt}";
    }
    echo '  ' . implode(', ', $parts) . "\n";
}

// -----------------------------------------------------------------------
// ADMIN ACTIONS
// -----------------------------------------------------------------------
echo "\nADMIN ACTIONS\n";
if (empty($adminActions)) {
    echo "  (none)\n";
} else {
    // Group config updates
    $configUpdates   = 0;
    $otherActions    = [];

    foreach ($adminActions as $e) {
        $msg = $e['message'];
        $ctx = $e['context'] ?? [];

        switch ($msg) {
            case 'admin.config_updated':
                $configUpdates++;
                break;
            case 'admin.user_admin_set':
                $targetId  = $ctx['user_id']             ?? '?';
                $actorId   = $ctx['changed_by_user_id']  ?? '?';
                $granted   = $ctx['is_admin'] ?? null;
                $action    = $granted ? 'granted admin' : 'revoked admin';
                $otherActions[] = "user#{$targetId} {$action} by user#{$actorId}";
                break;
            case 'admin.whitelist_added':
                $crew = $ctx['crew_key'] ?? '?';
                $boat = $ctx['boat_key'] ?? '?';
                $otherActions[] = "whitelist: added {$boat} for {$crew}";
                break;
            case 'admin.whitelist_removed':
                $crew = $ctx['crew_key'] ?? '?';
                $boat = $ctx['boat_key'] ?? '?';
                $otherActions[] = "whitelist: removed {$boat} for {$crew}";
                break;
            case 'admin.crew_profile_updated':
                $crew = $ctx['crew_key'] ?? '?';
                $otherActions[] = "crew profile updated: {$crew}";
                break;
            case 'admin.commitment_rank_set':
                $crew = $ctx['crew_key'] ?? '?';
                $rank = $ctx['rank']     ?? '?';
                $otherActions[] = "commitment rank set: {$crew} → {$rank}";
                break;
            default:
                $otherActions[] = $msg . (empty($ctx) ? '' : ' ' . json_encode($ctx));
                break;
        }
    }

    if ($configUpdates > 0) {
        $label = $configUpdates === 1 ? 'Config updated' : "Config updated (x{$configUpdates})";
        echo "  - {$label}\n";
    }
    foreach ($otherActions as $line) {
        echo "  - {$line}\n";
    }
}

echo "\n";
exit(0);
