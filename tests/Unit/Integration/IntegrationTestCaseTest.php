<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Unit tests for IntegrationTestCase base class
 *
 * Verifies that:
 * - Phinx migrations run successfully
 * - Database schema is complete with all migrations
 * - Season config is initialized
 * - PDO connection is properly configured
 */
class IntegrationTestCaseTest extends IntegrationTestCase
{
    public function testPdoIsConfigured(): void
    {
        $this->assertNotNull($this->pdo);
        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $this->pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertEquals(PDO::FETCH_ASSOC, $this->pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function testForeignKeysEnabled(): void
    {
        $result = $this->pdo->query("PRAGMA foreign_keys")->fetch();
        $this->assertEquals('1', $result['foreign_keys'], 'Foreign keys should be enabled');
    }

    public function testAllMigrationsRan(): void
    {
        // Verify phinxlog table exists
        $result = $this->pdo->query("
            SELECT name FROM sqlite_master WHERE type='table' AND name='phinxlog'
        ")->fetchAll();
        $this->assertNotEmpty($result, 'phinxlog table should exist after running migrations');

        // Verify all 9 migrations executed
        $versions = $this->pdo->query("
            SELECT version FROM phinxlog ORDER BY version
        ")->fetchAll(PDO::FETCH_COLUMN);

        $expected = [
            20260101000000,  // initial_schema
            20260130000000,  // add_users_authentication
            20260131000000,  // add_last_logout_column
            20260201000000,  // make_display_name_nullable
            20260221000000,  // remove_crew_rank_flexibility
            20260224000000,  // remove_email_columns
            20260227000000,  // add_cron_notifications
            20260302000000,  // create_locks_table
            20260316000000   // create_password_reset_tokens_table
        ];

        $this->assertEquals($expected, $versions, 'All 9 migrations should be applied');
    }

    public function testSeasonConfigInitialized(): void
    {
        $result = $this->pdo->query("SELECT * FROM season_config WHERE id = 1")->fetch();

        $this->assertNotEmpty($result, 'Season config should be initialized');
        $this->assertEquals(2026, $result['year']);
        $this->assertEquals('simulated', $result['source']);
        $this->assertEquals('2026-05-01 09:00:00', $result['simulated_date']);
        $this->assertEquals('12:45:00', $result['start_time']);
        $this->assertEquals('17:00:00', $result['finish_time']);
        $this->assertEquals('10:00:00', $result['blackout_from']);
        $this->assertEquals('18:00:00', $result['blackout_to']);
    }

    public function testLastLogoutColumnExists(): void
    {
        // Verify migration 20260131 ran successfully
        $result = $this->pdo->query("PRAGMA table_info(users)")->fetchAll();
        $columns = array_column($result, 'name');

        $this->assertContains(
            'last_logout',
            $columns,
            'Migration 20260131 should add last_logout column to users table'
        );
    }

    public function testCrewsDisplayNameNullable(): void
    {
        // Verify migration 20260201 ran successfully (nullable constraint)
        // Insert a crew without display_name to test nullability
        $stmt = $this->pdo->prepare("
            INSERT INTO crews (key, first_name, last_name)
            VALUES (?, ?, ?)
        ");
        $stmt->execute(['test_crew', 'Test', 'Crew']);

        $result = $this->pdo->query("
            SELECT display_name FROM crews WHERE key = 'test_crew'
        ")->fetch();

        $this->assertNull(
            $result['display_name'],
            'display_name should be nullable after migration 20260201'
        );
    }

    public function testCoreTablesExist(): void
    {
        $expectedTables = [
            'boats',
            'crews',
            'events',
            'boat_availability',
            'crew_availability',
            'boat_history',
            'crew_history',
            'crew_whitelist',
            'season_config',
            'flotillas',
            'users',
            'locks',
            'password_reset_tokens',
            'phinxlog'
        ];

        $result = $this->pdo->query("
            SELECT name FROM sqlite_master WHERE type='table' ORDER BY name
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $result, "Table '{$table}' should exist");
        }
    }

    public function testCreateTestEventUtility(): void
    {
        $this->createTestEvent('Fri May 29', '2026-05-29');

        $result = $this->pdo->query("
            SELECT * FROM events WHERE event_id = 'Fri May 29'
        ")->fetch();

        $this->assertNotEmpty($result);
        $this->assertEquals('2026-05-29', $result['event_date']);
        $this->assertEquals('upcoming', $result['status']);
    }

    public function testCreateTestUserUtility(): void
    {
        $userId = $this->createTestUser('test@example.com', 'crew', false);

        $result = $this->pdo->query("
            SELECT * FROM users WHERE id = {$userId}
        ")->fetch();

        $this->assertNotEmpty($result);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals('crew', $result['account_type']);
        $this->assertEquals(0, $result['is_admin']);
        $this->assertNotEmpty($result['password_hash']);
    }

    public function testCreateTestUserWithAdminFlag(): void
    {
        $userId = $this->createTestUser('admin@example.com', 'crew', true);

        $result = $this->pdo->query("
            SELECT is_admin FROM users WHERE id = {$userId}
        ")->fetch();

        $this->assertEquals(1, $result['is_admin']);
    }

    public function testPasswordIsHashedInCreateTestUser(): void
    {
        $userId = $this->createTestUser('test@example.com');

        $result = $this->pdo->query("
            SELECT password_hash FROM users WHERE id = {$userId}
        ")->fetch();

        // Verify it's a bcrypt hash (starts with $2y$)
        $this->assertStringStartsWith('$2y$', $result['password_hash']);
        // Verify password_verify works
        $this->assertTrue(password_verify('TestPass123', $result['password_hash']));
    }
}
