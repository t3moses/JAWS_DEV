<?php

declare(strict_types=1);

namespace App\Application\DTO\Request;

/**
 * Flag Assigned Crew Request DTO
 *
 * Data transfer object for a boat owner flagging crew members who were
 * assigned to their boat for one or more events.
 */
final readonly class FlagAssignedCrewRequest
{
    /**
     * @param array<int, array{eventId: string, crewKey: string}> $flags
     */
    public function __construct(
        public array $flags,
    ) {
    }

    /**
     * Create from array (e.g., HTTP request data)
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            flags: $data['flags'] ?? [],
        );
    }

    /**
     * Validate the request
     *
     * @return array<string, string> Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        foreach ($this->flags as $index => $flag) {
            if (!is_array($flag)) {
                $errors["flags[$index]"] = 'Each flag must be an object';
                continue;
            }

            if (empty($flag['eventId']) || !is_string($flag['eventId'])) {
                $errors["flags[$index].eventId"] = 'Event ID is required and must be a string';
            }

            if (empty($flag['crewKey']) || !is_string($flag['crewKey'])) {
                $errors["flags[$index].crewKey"] = 'Crew key is required and must be a string';
            }
        }

        return $errors;
    }
}
