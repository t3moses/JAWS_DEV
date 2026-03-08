<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use PDO;
use PDOException;

/**
 * SQLite Database Connection
 *
 * Singleton PDO connection manager for SQLite database.
 * Handles connection creation, foreign key enforcement, and error modes.
 */
class Connection
{
    private static ?PDO $instance = null;
    private static ?string $databasePath = null;
    private static ?PDO $testInstance = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Get PDO instance (singleton)
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getInstance(): PDO
    {
        // Return test instance if set (for unit testing)
        if (self::$testInstance !== null) {
            return self::$testInstance;
        }

        if (self::$instance === null) {
            $dbPath = self::getDatabasePath();

            try {
                self::$instance = new PDO('sqlite:' . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Enable foreign key constraints
                self::$instance->exec('PRAGMA foreign_keys = ON');

                // Enable WAL mode for better concurrency
                self::$instance->exec('PRAGMA journal_mode = WAL');

                // Set busy timeout as defense-in-depth for DB operations not covered by the lock
                self::$instance->exec('PRAGMA busy_timeout = 5000');
            } catch (PDOException $e) {
                throw new PDOException(
                    "Failed to connect to SQLite database at {$dbPath}: " . $e->getMessage(),
                    (int)$e->getCode(),
                    $e
                );
            }
        }

        return self::$instance;
    }

    /**
     * Set custom database path
     *
     * Must be called before first getInstance() call.
     *
     * @param string $path Absolute path to SQLite database file
     * @return void
     */
    public static function setDatabasePath(string $path): void
    {
        if (self::$instance !== null) {
            throw new \RuntimeException('Cannot change database path after connection is established');
        }

        self::$databasePath = $path;
    }

    /**
     * Get database path
     *
     * @return string
     */
    private static function getDatabasePath(): string
    {
        if (self::$databasePath !== null) {
            return self::$databasePath;
        }

        // Try environment variable first
        $envPath = getenv('DB_PATH');
        if ($envPath !== false) {
            return $envPath;
        }

        // Default to database/jaws.db relative to project root
        $projectRoot = dirname(__DIR__, 4); // Go up from src/Infrastructure/Persistence/SQLite
        return $projectRoot . '/database/jaws.db';
    }

    /**
     * Begin a transaction
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit a transaction
     *
     * @return bool
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Rollback a transaction
     *
     * @return bool
     */
    public static function rollBack(): bool
    {
        return self::getInstance()->rollBack();
    }

    /**
     * Check if in a transaction
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }

    /**
     * Close the connection (for testing)
     *
     * @return void
     */
    public static function close(): void
    {
        self::$instance = null;
    }

    /**
     * Reset the connection (for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$databasePath = null;
        self::$testInstance = null;
    }

    /**
     * Set a test connection (for unit testing)
     *
     * Allows injecting an in-memory PDO instance for testing.
     * This instance will be returned by getInstance() until resetTestConnection() is called.
     *
     * @param PDO $pdo Test PDO instance (typically sqlite::memory:)
     * @return void
     */
    public static function setTestConnection(PDO $pdo): void
    {
        self::$testInstance = $pdo;
    }

    /**
     * Reset test connection (for unit testing)
     *
     * Clears the test PDO instance, allowing getInstance() to return
     * the normal database connection.
     *
     * @return void
     */
    public static function resetTestConnection(): void
    {
        self::$testInstance = null;
    }

    /**
     * Execute a raw SQL query
     *
     * @param string $sql
     * @return int Number of affected rows
     */
    public static function exec(string $sql): int
    {
        return self::getInstance()->exec($sql);
    }

    /**
     * Prepare a statement
     *
     * @param string $sql
     * @return \PDOStatement
     */
    public static function prepare(string $sql): \PDOStatement
    {
        return self::getInstance()->prepare($sql);
    }

    /**
     * Get last insert ID
     *
     * @return string
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Quote a string for SQL
     *
     * @param string $string
     * @return string
     */
    public static function quote(string $string): string
    {
        return self::getInstance()->quote($string);
    }
}
