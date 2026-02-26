<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\LockServiceInterface;
use App\Application\Exception\LockTimeoutException;
use App\Infrastructure\Persistence\SQLite\Connection;
use PDO;
use PDOException;

/**
 * SQLite Lock Service
 *
 * Implements application-level distributed locking using a database table.
 * SQLite lacks native advisory locks (unlike PostgreSQL), so we simulate
 * them using a locks table with unique constraint on lock_name.
 *
 * Features:
 * - Automatic cleanup of expired locks (from crashed processes)
 * - Polling retry logic with configurable wait time
 * - Process ID tracking for debugging
 * - Try-finally guarantee for lock release
 *
 * Lock Mechanism:
 * - Acquire: INSERT INTO locks (fails if lock exists due to unique constraint)
 * - Release: DELETE FROM locks WHERE lock_name = ?
 * - Expiration: DELETE FROM locks WHERE expires_at < CURRENT_TIMESTAMP
 */
class SQLiteLockService implements LockServiceInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
    }

    /**
     * {@inheritDoc}
     */
    public function acquire(string $lockName, int $timeoutSeconds = 60): bool
    {
        // Clean up expired locks first
        $this->cleanupExpiredLocks();

        // Calculate expiration timestamp
        $expiresAt = date('Y-m-d H:i:s', time() + $timeoutSeconds);
        $acquiredAt = date('Y-m-d H:i:s');
        $acquiredBy = $this->getProcessId();

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO locks (lock_name, acquired_at, acquired_by, expires_at)
                VALUES (:lock_name, :acquired_at, :acquired_by, :expires_at)
            ');

            $stmt->execute([
                ':lock_name' => $lockName,
                ':acquired_at' => $acquiredAt,
                ':acquired_by' => $acquiredBy,
                ':expires_at' => $expiresAt,
            ]);

            return true;
        } catch (PDOException $e) {
            // Unique constraint violation means lock is already held
            if ($e->getCode() === '23000' || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                return false;
            }

            // Other database errors should be thrown
            throw new \RuntimeException(
                "Failed to acquire lock '{$lockName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $lockName): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM locks WHERE lock_name = :lock_name');
            $stmt->execute([':lock_name' => $lockName]);

            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException("Lock '{$lockName}' does not exist or was already released");
            }
        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Failed to release lock '{$lockName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isLocked(string $lockName): bool
    {
        // Clean up expired locks first
        $this->cleanupExpiredLocks();

        try {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) FROM locks
                WHERE lock_name = :lock_name
                AND expires_at > :current_time
            ');

            $stmt->execute([
                ':lock_name' => $lockName,
                ':current_time' => date('Y-m-d H:i:s'),
            ]);

            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Failed to check lock '{$lockName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function executeWithLock(
        string $lockName,
        callable $callback,
        int $timeoutSeconds = 60,
        int $waitSeconds = 10
    ): mixed {
        // Try to acquire lock with retry logic
        $acquired = $this->acquireWithRetry($lockName, $timeoutSeconds, $waitSeconds);

        if (!$acquired) {
            throw new LockTimeoutException($lockName, $waitSeconds);
        }

        try {
            // Execute callback while holding lock
            return $callback();
        } finally {
            // Always release lock, even if callback throws
            try {
                $this->release($lockName);
            } catch (\RuntimeException $e) {
                // Log but don't throw - we don't want to mask callback exceptions
                error_log("Failed to release lock '{$lockName}': " . $e->getMessage());
            }
        }
    }

    /**
     * Acquire lock with polling retry logic
     *
     * @param string $lockName
     * @param int $timeoutSeconds Lock expiration time
     * @param int $waitSeconds Maximum time to wait for lock
     * @return bool True if acquired, false if timeout
     */
    private function acquireWithRetry(string $lockName, int $timeoutSeconds, int $waitSeconds): bool
    {
        $startTime = time();
        $retryInterval = 100000; // 100ms in microseconds

        while (true) {
            // Try to acquire lock
            if ($this->acquire($lockName, $timeoutSeconds)) {
                return true;
            }

            // Check if we've exceeded wait time
            if (time() - $startTime >= $waitSeconds) {
                return false;
            }

            // Wait before retrying
            usleep($retryInterval);
        }
    }

    /**
     * Clean up expired locks
     *
     * Removes locks that have exceeded their expiration time.
     * This handles orphaned locks from crashed processes.
     *
     * @return void
     */
    private function cleanupExpiredLocks(): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM locks WHERE expires_at < :current_time');
            $stmt->execute([':current_time' => date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            // Log but don't throw - cleanup failure shouldn't prevent lock acquisition
            error_log("Failed to clean up expired locks: " . $e->getMessage());
        }
    }

    /**
     * Get unique process identifier
     *
     * Combines process ID with unique ID for debugging.
     * Format: "12345-507f191e810c19729de860ea"
     *
     * @return string
     */
    private function getProcessId(): string
    {
        return getmypid() . '-' . uniqid();
    }
}
