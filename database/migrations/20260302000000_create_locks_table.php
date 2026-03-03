<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Create Locks Table Migration
 *
 * Creates locks table for application-level concurrency control:
 * - Prevents race conditions in ProcessSeasonUpdateUseCase
 * - Uses database table for advisory lock simulation (SQLite lacks native advisory locks)
 * - Supports automatic cleanup of expired locks from crashed processes
 *
 * Purpose: Enable serialization of concurrent season update pipeline executions
 *
 * Lock Mechanism:
 * - Lock acquisition via INSERT (unique constraint prevents duplicates)
 * - Lock expiration via expires_at (auto-cleanup on next acquire attempt)
 * - Process tracking via acquired_by (debugging crashed processes)
 */
final class CreateLocksTable extends AbstractMigration
{
    /**
     * Create locks table for application-level locking
     */
    public function change(): void
    {
        // ====================================================================
        // Table: locks
        // Stores active application-level locks for concurrency control
        // ====================================================================
        $locks = $this->table('locks', ['id' => false, 'primary_key' => 'lock_name']);
        $locks->addColumn('lock_name', 'string', ['limit' => 255, 'null' => false])
              ->addColumn('acquired_at', 'datetime', ['null' => false])
              ->addColumn('acquired_by', 'string', ['limit' => 255, 'null' => false, 'comment' => 'Process ID for debugging'])
              ->addColumn('expires_at', 'datetime', ['null' => false, 'comment' => 'Auto-cleanup timestamp'])
              ->addIndex(['expires_at'], ['name' => 'idx_locks_expires_at'])
              ->create();
    }
}
