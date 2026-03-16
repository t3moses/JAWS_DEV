<?php

declare(strict_types=1);

namespace App\Application\DTO\Request;

/**
 * Reset Password Request DTO
 *
 * Data Transfer Object for password-reset requests.
 */
final readonly class ResetPasswordRequest
{
    public function __construct(
        public string $token,
        public string $password,
    ) {
    }

    /**
     * Create from array (e.g., HTTP request data)
     *
     * @param array $data Request data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'] ?? '',
            password: $data['password'] ?? '',
        );
    }

    /**
     * Validate the request
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->token)) {
            $errors['token'] = 'Reset token is required';
        }

        if (empty($this->password)) {
            $errors['password'] = 'New password is required';
        }

        return $errors;
    }
}
