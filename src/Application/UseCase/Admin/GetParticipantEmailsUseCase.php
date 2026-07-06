<?php

declare(strict_types=1);

namespace App\Application\UseCase\Admin;

use App\Application\Exception\EventNotFoundException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Domain\ValueObject\EventId;

/**
 * Get Participant Emails Use Case
 *
 * Returns registered participant emails for an event, grouped by role.
 * Boat owners = boats with berths > 0; crew members = any crew_availability record.
 */
class GetParticipantEmailsUseCase
{
    public function __construct(
        private BoatRepositoryInterface $boatRepository,
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @param string $eventId
     * @return array{event_id: string, boat_owners: array{count: int, emails: array<string>}, crew_members: array{count: int, emails: array<string>}}
     * @throws EventNotFoundException
     */
    public function execute(string $eventId): array
    {
        $eventId = EventId::fromString($eventId);

        $eventData = $this->eventRepository->findById($eventId);
        if ($eventData === null) {
            throw new EventNotFoundException($eventId);
        }

        $boatEmails = $this->resolveBoatOwnerEmails($eventId);
        $crewEmails = $this->resolveCrewMemberEmails($eventId);

        return [
            'event_id'     => $eventId->toString(),
            'boat_owners'  => ['count' => count($boatEmails), 'emails' => array_values($boatEmails)],
            'crew_members' => ['count' => count($crewEmails), 'emails' => array_values($crewEmails)],
        ];
    }

    /** @return array<string, string> keyed by email for deduplication */
    public function resolveBoatOwnerEmails(EventId $eventId): array
    {
        $emails = [];
        foreach ($this->boatRepository->findAvailableForEvent($eventId) as $boat) {
            $userId = $boat->getOwnerUserId();
            if ($userId === null) {
                continue;
            }
            $user = $this->userRepository->findById($userId);
            $email = $user?->getEmail() ?? '';
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }
        return $emails;
    }

    /** @return array<string, string> keyed by email for deduplication */
    public function resolveCrewMemberEmails(EventId $eventId): array
    {
        $emails = [];
        foreach ($this->crewRepository->findAvailableForEvent($eventId) as $crew) {
            $userId = $crew->getUserId();
            if ($userId === null) {
                continue;
            }
            $user = $this->userRepository->findById($userId);
            $email = $user?->getEmail() ?? '';
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }
        return $emails;
    }
}
