<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Lock Timeout Exception
 *
 * Thrown when a lock cannot be acquired within the specified wait time.
 * This typically occurs when multiple users are updating data concurrently
 * and one request must wait for another to complete.
 *
 * Maps to HTTP 409 Conflict status code.
 */
class LockTimeoutException extends \RuntimeException
{
    public function __construct(string $lockName, int $waitSeconds)
    {
        parent::__construct(
            sprintf(
                'Could not acquire lock "%s" within %d seconds. Another process is holding the lock.',
                $lockName,
                $waitSeconds
            )
        );
    }
}
