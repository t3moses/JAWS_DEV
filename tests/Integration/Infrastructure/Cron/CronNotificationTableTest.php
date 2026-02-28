<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Cron;

use Tests\Integration\IntegrationTestCase;

/**
 * Verifies that the cron_notifications table is correctly created by the Phinx migration
 * and that the UNIQUE(event_id, type) constraint prevents duplicate inserts.
 */
class CronNotificationTableTest extends IntegrationTestCase
{
    public function testCronNotificationsTableExists(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cron_notifications'");
        $row  = $stmt->fetch();

        $this->assertNotFalse($row, 'cron_notifications table should exist after migration');
        $this->assertSame('cron_notifications', $row['name']);
    }

    public function testCanInsertNotificationRecord(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Fri May 29', 'reminder', 5, 1]);

        $this->assertSame('1', $this->pdo->lastInsertId());
    }

    public function testUniqueConstraintPreventsSecondInsert(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Fri May 29', 'reminder', 5, 1]);

        // Second insert with same event_id + type should fail
        $this->expectException(\PDOException::class);
        $stmt->execute(['Fri May 29', 'reminder', 5, 1]);
    }

    public function testInsertOrIgnoreDoesNotThrow(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Fri May 29', 'crew_list', 3, 0]);
        $stmt->execute(['Fri May 29', 'crew_list', 3, 0]);  // Should be silently ignored

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM cron_notifications WHERE event_id='Fri May 29' AND type='crew_list'"
        )->fetchColumn();

        $this->assertSame(1, $count);
    }

    public function testDifferentTypesCanCoexistForSameEvent(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Fri May 29', 'reminder', 5, 1]);
        $stmt->execute(['Fri May 29', 'crew_list', 3, 0]);

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM cron_notifications WHERE event_id='Fri May 29'"
        )->fetchColumn();

        $this->assertSame(2, $count);
    }

    public function testDifferentEventsCanHaveSameType(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_notifications (event_id, type, recipients_count, skipped_count) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Fri May 29', 'reminder', 5, 1]);
        $stmt->execute(['Fri Jun 05', 'reminder', 4, 0]);

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM cron_notifications WHERE type='reminder'"
        )->fetchColumn();

        $this->assertSame(2, $count);
    }
}
