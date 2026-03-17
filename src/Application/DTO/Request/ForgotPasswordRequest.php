<?php

declare(strict_types=1);

namespace App\Application\DTO\Request;

/**
 * Forgot Password Request DTO
 *
 * Data Transfer Object for forgot-password requests.
 */
final readonly class ForgotPasswordRequest
{
    public function __construct(
        public string $email,
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
            email: $data['email'] ?? '',
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

        if (empty($this->email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        return $errors;
    }
}
