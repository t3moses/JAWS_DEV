<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Account Disabled Exception
 *
 * Thrown when a suspended (disabled) user attempts to authenticate or use an
 * existing token. Maps to HTTP 403 Forbidden response.
 */
class AccountDisabledException extends \RuntimeException
{
    public function __construct(string $message = 'This account has been deactivated. Please contact an administrator.')
    {
        parent::__construct($message);
    }
}
