<?php

declare(strict_types=1);

namespace App\Application\DTO\Request;

/**
 * Update Config Request DTO
 *
 * Request to update season configuration.
 */
class UpdateConfigRequest
{
    public function __construct(
        public readonly ?string $source = null,
        public readonly ?string $simulatedDate = null,
        public readonly ?int $year = null,
        public readonly ?string $startTime = null,
        public readonly ?string $finishTime = null,
        public readonly ?string $blackoutFrom = null,
        public readonly ?string $blackoutTo = null,
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
            source: $data['source'] ?? null,
            simulatedDate: $data['simulatedDate'] ?? null,
            year: $data['year'] ?? null,
            startTime: $data['startTime'] ?? null,
            finishTime: $data['finishTime'] ?? null,
            blackoutFrom: $data['blackoutFrom'] ?? null,
            blackoutTo: $data['blackoutTo'] ?? null,
        );
    }

    /**
     * Validate the request
     *
     * @return array<string, string> Validation errors (field => error message)
     */
    public function validate(): array
    {
        $errors = [];

        // Validate source
        if ($this->source !== null && !in_array($this->source, ['simulated', 'production'])) {
            $errors['source'] = 'Source must be either "simulated" or "production"';
        }

        // Validate simulatedDate format (YYYY-MM-DD HH:MM:SS)
        if ($this->simulatedDate !== null) {
            $date = \DateTime::createFromFormat('Y-m-d H:i:s', $this->simulatedDate);
            if (!$date || $date->format('Y-m-d H:i:s') !== $this->simulatedDate) {
                $errors['simulatedDate'] = 'Simulated date must be in format YYYY-MM-DD HH:MM:SS';
            }
        }

        // Validate year
        if ($this->year !== null && ($this->year < 2020 || $this->year > 2100)) {
            $errors['year'] = 'Year must be between 2020 and 2100';
        }

        // Validate time formats (HH:MM:SS)
        $timeFields = [
            'startTime' => $this->startTime,
            'finishTime' => $this->finishTime,
            'blackoutFrom' => $this->blackoutFrom,
            'blackoutTo' => $this->blackoutTo,
        ];

        foreach ($timeFields as $field => $value) {
            if ($value !== null && !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $value)) {
                // Convert camelCase to readable format for error message
                $readableField = preg_replace('/([A-Z])/', ' $1', $field);
                $readableField = ucfirst(strtolower($readableField));
                $errors[$field] = $readableField . ' must be in format HH:MM:SS';
            }
        }

        return $errors;
    }
}
