<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\SQLite\Connection;
use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Base class for integration tests requiring database access
 *
 * Provides:
 * - Phinx migration execution for in-memory SQLite
 * - Connection singleton management
 * - Common test data utilities
 *
 * Usage:
 * ```php
 * class MyIntegrationTest extends IntegrationTestCase
 * {
 *     protected function setUp(): void
 *     {
 *         parent::setUp();  // Runs all Phinx migrations + initializes season config
 *
 *         // Your test-specific setup
 *         $this->myRepository = new MyRepository();
 *     }
 * }
 * ```
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory database
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Enable foreign keys (required for schema integrity)
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // Run all Phinx migrations
        $this->runMigrations();

        // Initialize season config (most tests need this)
        $this->initializeSeasonConfig();

        // Set test connection globally
        Connection::setTestConnection($this->pdo);
    }

    protected function tearDown(): void
    {
        Connection::resetTestConnection();
        parent::tearDown();
    }

    /**
     * Run Phinx migrations programmatically on in-memory database
     *
     * This method:
     * 1. Configures Phinx to use the existing in-memory PDO connection
     * 2. Points to the database/migrations directory
     * 3. Runs all pending migrations in the 'testing' environment
     * 4. Creates a phinxlog table to track applied migrations
     */
    protected function runMigrations(): void
    {
        $projectRoot = dirname(__DIR__, 2); // From tests/Integration to project root

        $config = new Config([
            'paths' => [
                'migrations' => $projectRoot . '/database/migrations'
            ],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'testing',
                'testing' => [
                    'adapter' => 'sqlite',
                    'connection' => $this->pdo,  // Inject existing PDO
                    'name' => ':memory:'          // Required by Phinx config
                ]
            ],
            'version_order' => 'creation'
        ]);

        $input = new StringInput('migrate -e testing');
        $output = new NullOutput();
        $manager = new Manager($config, $input, $output);
        $manager->migrate('testing');
    }

    /**
     * Initialize season config with test data
     *
     * Inserts a default season configuration with simulated date mode
     * for consistent test behavior.
     */
    protected function initializeSeasonConfig(): void
    {
        $this->pdo->exec("
            INSERT OR REPLACE INTO season_config (
                id, year, source, simulated_date,
                start_time, finish_time, blackout_from, blackout_to
            ) VALUES (
                1, 2026, 'simulated', '2026-05-01 09:00:00',
                '12:45:00', '17:00:00', '10:00:00', '18:00:00'
            )
        ");
    }

    // ==================== TEST UTILITIES ====================

    /**
     * Create test event
     *
     * @param string $eventId Event identifier (e.g., 'Fri May 29')
     * @param string $date Event date (YYYY-MM-DD format)
     * @return void
     */
    protected function createTestEvent(string $eventId, string $date): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO events (event_id, event_date, start_time, finish_time, status)
            VALUES (?, ?, '12:45:00', '17:00:00', 'upcoming')
        ");
        $stmt->execute([$eventId, $date]);
    }

    /**
     * Create test user (for authentication tests)
     *
     * @param string $email User email address
     * @param string $accountType Account type ('crew' or 'boat_owner')
     * @param bool $isAdmin Whether user has admin privileges
     * @return int User ID
     */
    protected function createTestUser(
        string $email = 'test@example.com',
        string $accountType = 'crew',
        bool $isAdmin = false
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO users (email, password_hash, account_type, is_admin, created_at, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $email,
            password_hash('TestPass123', PASSWORD_BCRYPT),
            $accountType,
            $isAdmin ? 1 : 0
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
