<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Invalid Reset Token Exception
 *
 * Thrown when a password reset token is missing, expired, or already used.
 * Maps to HTTP 400 Bad Request response.
 */
class InvalidResetTokenException extends \RuntimeException
{
    public function __construct(string $message = 'Invalid or expired reset token')
    {
        parent::__construct($message);
    }
}
