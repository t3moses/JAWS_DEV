<?php

declare(strict_types=1);

namespace App\Application\Port\Service;

use App\Application\Exception\LockTimeoutException;

/**
 * Lock Service Interface
 *
 * Defines the contract for application-level distributed locking.
 * Used to serialize access to critical sections (e.g., season update pipeline)
 * and prevent race conditions when multiple users update data concurrently.
 *
 * Implementation note: SQLite lacks native advisory locks, so implementations
 * typically use a database table with unique constraints for lock simulation.
 */
interface LockServiceInterface
{
    /**
     * Attempt to acquire a named lock
     *
     * @param string $lockName Unique identifier for the lock (e.g., 'season_update_pipeline')
     * @param int $timeoutSeconds Lock expiration time in seconds (default: 60)
     * @return bool True if lock acquired, false if already held by another process
     * @throws \RuntimeException If database error occurs during acquisition
     */
    public function acquire(string $lockName, int $timeoutSeconds = 60): bool;

    /**
     * Release a named lock
     *
     * @param string $lockName The lock to release
     * @return void
     * @throws \RuntimeException If lock does not exist or database error occurs
     */
    public function release(string $lockName): void;

    /**
     * Check if a named lock is currently held
     *
     * @param string $lockName The lock to check
     * @return bool True if lock is held (and not expired), false otherwise
     */
    public function isLocked(string $lockName): bool;

    /**
     * Execute a callback with lock protection
     *
     * Acquires the lock, executes the callback, and releases the lock.
     * Provides try-finally guarantee: lock is released even if callback throws.
     * Supports waiting for lock with retry logic.
     *
     * @param string $lockName Unique identifier for the lock
     * @param callable $callback Function to execute while holding lock
     * @param int $timeoutSeconds Lock expiration time (default: 60)
     * @param int $waitSeconds Maximum time to wait for lock acquisition (default: 10)
     * @return mixed Return value from callback
     * @throws LockTimeoutException If lock cannot be acquired within waitSeconds
     * @throws \Throwable If callback throws an exception (lock is still released)
     */
    public function executeWithLock(
        string $lockName,
        callable $callback,
        int $timeoutSeconds = 60,
        int $waitSeconds = 10
    ): mixed;
}
