<?php

declare(strict_types=1);

namespace App\Application\Port\Service;

/**
 * Transaction Service Interface
 *
 * Abstracts database transaction management so Application layer use cases
 * can wrap operations in a transaction without depending on Infrastructure.
 */
interface TransactionServiceInterface
{
    public function begin(): void;
    public function commit(): void;
    public function rollBack(): void;
}
