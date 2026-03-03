<?php

declare(strict_types=1);

namespace App\Application\UseCase\Season;

use App\Application\Exception\LockTimeoutException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Service\LockServiceInterface;
use App\Application\Port\Service\TransactionServiceInterface;
use App\Domain\Collection\Fleet;
use App\Domain\Collection\Squad;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Service\SelectionService;
use App\Domain\Service\AssignmentService;
use App\Domain\Service\RankingService;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\Rank;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;

/**
 * Process Season Update Use Case
 *
 * CRITICAL: This orchestrates the Selection → Assignment → Persistence pipeline
 * that was previously handled by season_update.php.
 *
 * Pipeline:
 * 1. Load Fleet, Squad, Season configuration
 * 2. For each future event:
 *    - Selection phase (rank and capacity match)
 *    - Event consolidation (form flotilla structure)
 *    - Assignment optimization (next event only - greedy swap optimization)
 *    - Update availability statuses
 *    - Update history
 *    - Save flotilla
 * 3. Persist all changes
 */
class ProcessSeasonUpdateUseCase
{
    public function __construct(
        private BoatRepositoryInterface $boatRepository,
        private CrewRepositoryInterface $crewRepository,
        private EventRepositoryInterface $eventRepository,
        private SeasonRepositoryInterface $seasonRepository,
        private SelectionService $selectionService,
        private AssignmentService $assignmentService,
        private RankingService $rankingService,
        private TransactionServiceInterface $transactionService,
        private LockServiceInterface $lockService,
    ) {
    }

    /**
     * Execute the season update pipeline (with concurrency lock)
     *
     * Wraps run() in an application-level lock so that reads, compute,
     * and writes all happen serially, preventing stale-read races.
     *
     * @return array{success: bool, events_processed: int, flotillas_generated: int}
     */
    public function execute(): array
    {
        try {
            return $this->lockService->executeWithLock(
                lockName: 'season_update_pipeline',
                callback: fn() => $this->run(),
                timeoutSeconds: 60,
                waitSeconds: 10
            );
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException(
                'Season update is currently in progress by another user. Please try again in a moment.',
                409,
                $e
            );
        }
    }

    /**
     * Run the season update pipeline (reads + compute + writes)
     *
     * @return array{success: bool, events_processed: int, flotillas_generated: int}
     */
    private function run(): array
    {
        // Load all entities (reads only — outside the transaction)
        $fleet = $this->loadFleet();
        $squad = $this->loadSquad();
        $futureEvents = $this->eventRepository->findFutureEvents();
        $nextEventId = $this->eventRepository->findNextEvent();

        // Sync boat participation history from past flotillas and update absence ranks
        $boatHistoryUpdates = $this->syncBoatHistory($fleet);

        $eventsProcessed = 0;
        $flotillasGenerated = 0;
        $modifiedCrews = [];
        $commitmentCrews = null;

        // Process each future event (in-memory only — no DB writes yet)
        foreach ($futureEvents as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);

            // Phase 1: Selection (rank and capacity match)
            $selectionResult = $this->runSelection($fleet, $squad, $eventId);

            // Add flex boat owners to crew waitlist when their boat is cut
            $existingCrewDisplayNames = array_values(array_filter(
                array_map(fn($c) => $c->getDisplayName(), $squad->all()),
                fn($name) => $name !== null
            ));
            $flexCrewEntries = $this->buildFlexCrewEntries(
                $selectionResult['waitlisted_boats'],
                $existingCrewDisplayNames
            );
            $selectionResult['waitlisted_crews'] = array_merge(
                $selectionResult['waitlisted_crews'],
                $flexCrewEntries
            );

            // Phase 2: Event consolidation (form flotilla structure)
            $flotilla = $this->consolidateEvent(
                $eventId,
                $selectionResult['selected_boats'],
                $selectionResult['selected_crews'],
                $selectionResult['waitlisted_boats'],
                $selectionResult['waitlisted_crews']
            );

            // Phase 3: Assignment optimization (next event only)
            if ($eventIdString === $nextEventId) {
                $flotilla = $this->runAssignment($flotilla);

                // Update commitment ranks for all crew based on assignment result
                // Assigned crew get rank=3; others are set by availability; admin penalties (rank=1) persist
                $assignedCrewKeys = array_map(
                    fn(Crew $crew) => $crew->getKey()->toString(),
                    $selectionResult['selected_crews']
                );
                $allCrews = $squad->all();
                $this->rankingService->updateCrewCommitmentRanks($allCrews, $eventId, $assignedCrewKeys);
                $commitmentCrews = $allCrews;
            }

            // Phase 3.5: Promote waitlisted crews to boats with spare capacity
            $flotilla = $this->promoteWaitlistCrew($flotilla, $eventId);

            // Phase 4: Update availability statuses
            $modified = $this->updateAvailabilityStatuses(
                $selectionResult['selected_boats'],
                $selectionResult['selected_crews'],
                $eventId
            );
            // Track modified crews (keyed by crew key to avoid duplicates)
            $modifiedCrews = array_merge($modifiedCrews, $modified);

            // Phase 5: Update history (for past events - not applicable here for future events)
            // History is updated after events occur via separate process

            // Stash serialized flotilla for bulk write
            $serializedFlotillas[$eventIdString] = $this->serializeFlotilla($flotilla);
            $flotillasGenerated++;

            $eventsProcessed++;
        }

        // Persist all writes in a single transaction
        $this->transactionService->begin();
        try {
            foreach ($serializedFlotillas ?? [] as $eventIdString => $serializedFlotilla) {
                $this->seasonRepository->saveFlotilla(EventId::fromString($eventIdString), $serializedFlotilla);
            }

            $this->persistChanges($modifiedCrews);

            if ($commitmentCrews !== null) {
                $this->persistCommitmentRanks($commitmentCrews);
            }

            // Persist boat history entries (upsert — idempotent)
            foreach ($boatHistoryUpdates as [$boatKey, $eventId, $participated]) {
                $this->boatRepository->updateHistory($boatKey, $eventId, $participated);
            }

            // Persist updated absence ranks
            foreach ($fleet->all() as $boat) {
                $this->boatRepository->updateRankAbsence($boat);
            }

            $this->transactionService->commit();
        } catch (\Throwable $e) {
            $this->transactionService->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'events_processed' => $eventsProcessed,
            'flotillas_generated' => $flotillasGenerated,
        ];
    }

    /**
     * Load all boats from repository into Fleet collection
     */
    private function loadFleet(): Fleet
    {
        $boats = $this->boatRepository->findAll();
        $fleet = new Fleet();

        foreach ($boats as $boat) {
            $fleet->add($boat);
        }

        return $fleet;
    }

    /**
     * Load all crews from repository into Squad collection
     */
    private function loadSquad(): Squad
    {
        $crews = $this->crewRepository->findAll();
        $squad = new Squad();

        foreach ($crews as $crew) {
            $squad->add($crew);
        }

        return $squad;
    }

    /**
     * Run Selection phase: rank boats and crews, match capacity
     *
     * @param Fleet $fleet
     * @param Squad $squad
     * @param EventId $eventId
     * @return array{selected_boats: array<Boat>, selected_crews: array<Crew>, waitlisted_boats: array<Boat>, waitlisted_crews: array<Crew>}
     */
    private function runSelection(Fleet $fleet, Squad $squad, EventId $eventId): array
    {
        // Get available boats and crews for this event
        $availableBoats = $fleet->getAvailableFor($eventId);
        $availableCrews = $squad->getAvailableFor($eventId);

        // Run selection algorithm (deterministic shuffle, bubble sort, capacity matching)
        $this->selectionService->select(
            $availableBoats,
            $availableCrews,
            $eventId
        );

        // Retrieve results from selection service
        return [
            'selected_boats' => $this->selectionService->getSelectedBoats(),
            'selected_crews' => $this->selectionService->getSelectedCrews(),
            'waitlisted_boats' => $this->selectionService->getWaitlistBoats(),
            'waitlisted_crews' => $this->selectionService->getWaitlistCrews(),
        ];
    }

    /**
     * Consolidate event into flotilla structure
     *
     * @param EventId $eventId
     * @param array<Boat> $selectedBoats
     * @param array<Crew> $selectedCrews
     * @param array<Boat> $waitlistedBoats
     * @param array<Crew> $waitlistedCrews
     * @return array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array}
     */
    private function consolidateEvent(
        EventId $eventId,
        array $selectedBoats,
        array $selectedCrews,
        array $waitlistedBoats,
        array $waitlistedCrews
    ): array {
        // Build crewed boats array (from Selection output)
        $crewedBoats = [];
        foreach ($selectedBoats as $boat) {
            $crewedBoats[] = [
                'boat' => $boat,
                'crews' => [], // Will be populated by assignment or left empty for initial selection
            ];
        }

        // Distribute selected crews to boats based on each boat's occupied_berths
        // This respects the capacity-aware distribution calculated by SelectionService
        $crewIndex = 0;
        foreach ($crewedBoats as &$crewedBoat) {
            $boat = $crewedBoat['boat'];
            $crewsForThisBoat = $boat->occupied_berths;

            for ($i = 0; $i < $crewsForThisBoat && $crewIndex < count($selectedCrews); $i++) {
                $crewedBoat['crews'][] = $selectedCrews[$crewIndex];
                $crewIndex++;
            }
        }
        unset($crewedBoat); // Break the reference

        return [
            'event_id' => $eventId->toString(),
            'crewed_boats' => $crewedBoats,
            'waitlist_boats' => $waitlistedBoats,
            'waitlist_crews' => $waitlistedCrews,
        ];
    }

    /**
     * Run Assignment optimization: greedy swap algorithm to minimize rule violations
     *
     * CRITICAL: Only run on next event, not all future events
     *
     * @param array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array} $flotilla
     * @return array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array}
     */
    private function runAssignment(array $flotilla): array
    {
        // Run assignment optimization (6 rules: ASSIST, WHITELIST, HIGH_SKILL, LOW_SKILL, PARTNER, REPEAT)
        // The assign() method expects the full flotilla structure and returns it optimized
        return $this->assignmentService->assign($flotilla);
    }

    /**
     * Update availability statuses for selected boats and crews
     *
     * Selected entities get status GUARANTEED (2)
     *
     * @param array<Boat> $selectedBoats
     * @param array<Crew> $selectedCrews
     * @param EventId $eventId
     * @return array<string, Crew> Modified crews keyed by crew key
     */
    private function updateAvailabilityStatuses(
        array $selectedBoats,
        array $selectedCrews,
        EventId $eventId
    ): array {
        // Update boat statuses (boats don't have availability status in same way, but we track selection via berths)
        // No-op for boats as berths already indicate selection

        // Update crew statuses to GUARANTEED and track modified crews
        $modifiedCrews = [];
        foreach ($selectedCrews as $crew) {
            $crew->setAvailability($eventId, AvailabilityStatus::GUARANTEED);
            $modifiedCrews[$crew->getKey()->toString()] = $crew;
        }

        return $modifiedCrews;
    }

    /**
     * Serialize flotilla domain objects to arrays for JSON storage
     *
     * Converts Boat and Crew domain objects to arrays so they can be properly
     * stored in the database and retrieved later.
     *
     * @param array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array} $flotilla
     * @return array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array}
     */
    private function serializeFlotilla(array $flotilla): array
    {
        $serializedCrewedBoats = [];
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            $serializedCrews = [];
            foreach ($crewedBoat['crews'] as $crew) {
                $serializedCrews[] = $crew->toArray();
            }
            $serializedCrewedBoats[] = [
                'boat' => $crewedBoat['boat']->toArray(),
                'crews' => $serializedCrews,
            ];
        }

        $serializedWaitlistBoats = [];
        foreach ($flotilla['waitlist_boats'] as $boat) {
            $serializedWaitlistBoats[] = $boat->toArray();
        }

        $serializedWaitlistCrews = [];
        foreach ($flotilla['waitlist_crews'] as $crew) {
            $serializedWaitlistCrews[] = $crew->toArray();
        }

        return [
            'event_id' => $flotilla['event_id'],
            'crewed_boats' => $serializedCrewedBoats,
            'waitlist_boats' => $serializedWaitlistBoats,
            'waitlist_crews' => $serializedWaitlistCrews,
        ];
    }

    /**
     * Persist only modified crew availability statuses to database
     *
     * Instead of saving entire crew entities (which would re-save all availability,
     * history, and whitelist data), we directly update only the specific availability
     * statuses that changed. This dramatically reduces database operations.
     *
     * @param array<string, Crew> $modifiedCrews Modified crews keyed by crew key
     */
    private function persistChanges(array $modifiedCrews): void
    {
        // For each modified crew, update only their GUARANTEED availability statuses
        foreach ($modifiedCrews as $crew) {
            foreach ($crew->getAllAvailability() as $eventIdString => $status) {
                if ($status === AvailabilityStatus::GUARANTEED) {
                    $this->crewRepository->updateAvailability(
                        $crew->getKey(),
                        EventId::fromString($eventIdString),
                        $status
                    );
                }
            }
        }
    }

    /**
     * Resolve a unique display name by appending an incrementing counter on collision.
     *
     * Returns $base if it is available (not in $usedNames), otherwise tries
     * $base . "2", $base . "3", … until a free name is found.
     *
     * @param string   $base      The desired display name
     * @param callable $exists    fn(string $name): bool — returns true when the name is taken
     * @return string             A display name that is not taken
     */
    private function resolveUniqueDisplayName(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists($base . $counter)) {
            $counter++;
        }
        return $base . $counter;
    }

    /**
     * Build Crew entities for flex boat owners whose boat was waitlisted
     *
     * When a flex boat (rank_flexibility=0) is waitlisted, its owner should appear
     * in the crew waitlist so they can be promoted to another boat with spare capacity.
     *
     * Display names are resolved against both the real crew squad and previously
     * created flex entries in the same pass.
     *
     * @param array<Boat>   $waitlistedBoats      Boats that were not selected
     * @param array<string> $existingDisplayNames  Display names already in use by real crew
     * @return array<Crew> Crew entities for flex boat owners
     */
    private function buildFlexCrewEntries(array $waitlistedBoats, array $existingDisplayNames): array
    {
        $entries = [];
        $usedNames = $existingDisplayNames;

        foreach ($waitlistedBoats as $boat) {
            if ($boat->isWillingToCrew()) {
                $displayName = $this->resolveUniqueDisplayName(
                    $boat->getOwnerDisplayName(),
                    fn($name) => in_array($name, $usedNames, true)
                );
                $usedNames[] = $displayName;

                $crew = new Crew(
                    key: CrewKey::fromName($boat->getOwnerFirstName(), $boat->getOwnerLastName()),
                    displayName: $displayName,
                    firstName: $boat->getOwnerFirstName(),
                    lastName: $boat->getOwnerLastName(),
                    partnerKey: null,
                    mobile: $boat->getOwnerMobile(),
                    socialPreference: $boat->hasSocialPreference(),
                    membershipNumber: '99999',
                    skill: SkillLevel::ADVANCED,
                    experience: null,
                );
                $crew->setRank(Rank::forCrew(
                    commitment: 2,  // Available (willing to crew)
                    membership: 1,  // Club member (implied by boat ownership)
                    absence: 0      // No crew absence history
                ));
                $entries[] = $crew;
            }
        }
        return $entries;
    }

    /**
     * Promote waitlisted crews into crewed boats that have spare berth capacity
     *
     * After selection (case 1 — too few crews), selected boats are filled to minBerths
     * but may have offered more berths. Waitlisted crew (including synthetic flex owner
     * entries) are promoted in priority order into those spare slots.
     *
     * Synthetic flex arrays are converted to Crew entities before being placed in
     * crewed_boats, so serializeFlotilla() can call toArray() uniformly.
     *
     * @param array $flotilla
     * @param EventId $eventId
     * @return array Updated flotilla with promotions applied
     */
    private function promoteWaitlistCrew(array $flotilla, EventId $eventId): array
    {
        if (empty($flotilla['waitlist_crews'])) {
            return $flotilla;
        }

        $waitlistCrews = $flotilla['waitlist_crews'];

        foreach ($flotilla['crewed_boats'] as &$crewedBoat) {
            $boat = $crewedBoat['boat'];
            $spare = $boat->getBerths($eventId) - count($crewedBoat['crews']);

            for ($i = 0; $i < $spare && !empty($waitlistCrews); $i++) {
                $crewedBoat['crews'][] = array_shift($waitlistCrews);
            }

            if (empty($waitlistCrews)) {
                break;
            }
        }
        unset($crewedBoat);

        $flotilla['waitlist_crews'] = $waitlistCrews;
        return $flotilla;
    }

    /**
     * Sync boat participation history from stored flotillas for past events.
     *
     * For each past event that has a flotilla:
     *   - Boats in crewed_boats → 'Y'
     *   - All other boats       → ''
     *
     * Updates absence rank on each boat in-memory so selection uses correct ranks.
     *
     * @param Fleet $fleet All boats (mutated in place)
     * @return array<array{BoatKey, EventId, string}> Pending DB writes
     */
    private function syncBoatHistory(Fleet $fleet): array
    {
        $pastEventIds = $this->eventRepository->findPastEvents();
        if (empty($pastEventIds)) {
            return [];
        }

        $allBoats = $fleet->all();
        $updates = [];
        $eventIdsWithFlotilla = [];

        foreach ($pastEventIds as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);
            $flotilla = $this->seasonRepository->getFlotilla($eventId);
            if ($flotilla === null) {
                continue; // No flotilla data — skip (don't penalise boats)
            }

            $eventIdsWithFlotilla[] = $eventIdString;

            // Extract keys of boats that were in the flotilla
            $participatedKeys = array_map(
                fn($entry) => $entry['boat']['key'],
                $flotilla['crewed_boats']
            );

            foreach ($allBoats as $boat) {
                $participated = in_array($boat->getKey()->toString(), $participatedKeys, true) ? 'Y' : '';
                $boat->setHistory($eventId, $participated);
                $updates[] = [$boat->getKey(), $eventId, $participated];
            }
        }

        // Recompute absence ranks (only counts events that had a flotilla)
        if (!empty($eventIdsWithFlotilla)) {
            $this->rankingService->updateBoatAbsenceRanks($allBoats, $eventIdsWithFlotilla);
        }

        return $updates;
    }

    /**
     * Persist updated commitment ranks to database (ONLY rank_commitment column)
     *
     * @param array<Crew> $crews Crews with updated commitment ranks
     */
    private function persistCommitmentRanks(array $crews): void
    {
        foreach ($crews as $crew) {
            $this->crewRepository->updateRankCommitment($crew);
        }
    }
}
