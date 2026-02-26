<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\TransactionServiceInterface;
use App\Infrastructure\Persistence\SQLite\Connection;

/**
 * Database Transaction Service
 *
 * Implements TransactionServiceInterface using the SQLite Connection singleton.
 */
class DatabaseTransactionService implements TransactionServiceInterface
{
    public function begin(): void
    {
        Connection::beginTransaction();
    }

    public function commit(): void
    {
        Connection::commit();
    }

    public function rollBack(): void
    {
        Connection::rollBack();
    }
}
