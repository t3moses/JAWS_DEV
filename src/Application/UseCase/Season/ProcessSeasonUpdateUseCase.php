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
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
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
        $crewHistoryUpdates = $this->syncCrewHistory($squad);

        $this->logger->info('season_update.start', [
            'future_events_count' => count($futureEvents),
            'next_event_id'       => $nextEventId,
        ]);

        $eventsProcessed = 0;
        $flotillasGenerated = 0;
        $modifiedCrews = [];
        $serializedFlotillas = [];

        // Process each future event (in-memory only — no DB writes yet)
        foreach ($futureEvents as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);

            // Refresh availability rank in-memory for THIS event before selecting it.
            // Each event has its own crew_availability status, so this must be
            // recalculated per event rather than reused across the whole loop.
            $this->rankingService->updateCrewAvailabilityRanks($squad->all(), $eventId);

            // Phase 1: Selection (rank and capacity match)
            $selectionResult = $this->runSelection($fleet, $squad, $eventId);

            $this->logger->debug('season_update.selection_complete', [
                'event_id'         => $eventId->toString(),
                'selected_boats'   => count($selectionResult['selected_boats']),
                'selected_crews'   => count($selectionResult['selected_crews']),
                'waitlisted_boats' => count($selectionResult['waitlisted_boats']),
                'waitlisted_crews' => count($selectionResult['waitlisted_crews']),
            ]);

            // Shuffle selected crews deterministically before they're paired to boats,
            // so boat assignment doesn't just mirror rank order. Waitlist crews are left
            // in rank order since promotion later relies on that ordering.
            $selectionResult['selected_crews'] = $this->shuffleDeterministically(
                $selectionResult['selected_crews'],
                $eventId
            );

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

                $this->logger->info('season_update.assignment_complete', [
                    'event_id'           => $eventId->toString(),
                    'crewed_boats_count' => count($flotilla['crewed_boats']),
                ]);
            }

            // Phase 3.5: Promote waitlisted crews to boats with spare capacity
            $flotilla = $this->promoteWaitlistCrew($flotilla, $eventId);

            // If a boat's capacity decreased since it was last selected, trim the lowest-ranked
            // crews to fit (next event only)
            if ($eventIdString === $nextEventId) {
                $flotilla = $this->enforceCapacityConstraints($flotilla, $eventId);
            }

            // Phase 4: Update availability status — next event only, reflecting the FINAL
            // flotilla (after promotion/capacity enforcement above). Every crew with an
            // existing crew_availability record for this event is set to SELECTED or
            // NOT_SELECTED; crews without a record (withdrawn) are left untouched.
            if ($eventIdString === $nextEventId) {
                $modifiedCrews = $this->updateAvailabilityStatuses(
                    $squad->getAvailableFor($eventId),
                    $flotilla,
                    $eventId
                );
            }

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
            $flotillasSaved = 0;
            foreach ($serializedFlotillas as $eventIdString => $serializedFlotilla) {
                $this->seasonRepository->saveFlotilla(EventId::fromString($eventIdString), $serializedFlotilla);
                $flotillasSaved++;
            }
            $this->logger->debug('season_update.flotillas_persisted', [
                'flotillas_saved' => $flotillasSaved,
                'flotillas_expected' => $flotillasGenerated,
            ]);

            if ($nextEventId !== null) {
                $this->persistChanges($modifiedCrews, EventId::fromString($nextEventId));
            }

            // Persist boat history entries (upsert — idempotent)
            foreach ($boatHistoryUpdates as [$boatKey, $eventId, $participated]) {
                $this->boatRepository->updateHistory($boatKey, $eventId, $participated);
            }

            // Persist updated boat absence ranks
            foreach ($fleet->all() as $boat) {
                $this->boatRepository->updateRankAbsence($boat);
            }

            // Persist crew history entries (upsert — idempotent)
            foreach ($crewHistoryUpdates as [$crewKey, $eventId, $boatKey]) {
                $this->crewRepository->updateHistory($crewKey, $eventId, $boatKey);
            }

            // Persist updated crew absence ranks
            foreach ($squad->all() as $crew) {
                $this->crewRepository->updateRankAbsence($crew);
            }

            $this->transactionService->commit();
        } catch (\Throwable $e) {
            $this->transactionService->rollBack();
            $this->logger->error('season_update.transaction_failed', [
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('season_update.complete', [
            'events_processed' => $eventsProcessed,
        ]);

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
     * Deterministically shuffle a list, seeded by event ID so the same event always
     * produces the same order. Mirrors SelectionService's own CRC32-seeded shuffle.
     *
     * @param array<Crew> $crews
     * @param EventId $eventId
     * @return array<Crew>
     */
    private function shuffleDeterministically(array $crews, EventId $eventId): array
    {
        mt_srand(crc32($eventId->toString()));
        shuffle($crews);

        return $crews;
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
            $maxCrewsForBoat = min($boat->occupied_berths, $boat->getBerths($eventId));
            $crewsForThisBoat = max(0, $maxCrewsForBoat);

            if ($crewsForThisBoat < $boat->occupied_berths) {
                $this->logger->warning('season_update.berth_capacity_capped', [
                    'event_id' => $eventId->toString(),
                    'boat_key' => $boat->getKey()->toString(),
                    'occupied_berths' => $boat->occupied_berths,
                    'available_berths' => $boat->getBerths($eventId),
                    'crews_assigned' => $crewsForThisBoat,
                ]);
            }

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
     * Update availability status for a single event (the next event) to match the
     * FINAL selection outcome: SELECTED (1) for every crew on a crewed boat,
     * NOT_SELECTED (0) for every other crew that already has a crew_availability
     * record for this event.
     *
     * Crews without an existing record are never included in $availableCrews and are
     * therefore left untouched — row absence represents withdrawal and must never be
     * turned into a status=0 row.
     *
     * @param array<Crew> $availableCrews Crews with an existing crew_availability record for $eventId
     * @param array{event_id: string, crewed_boats: array, waitlist_boats: array, waitlist_crews: array} $flotilla
     *        Final flotilla for $eventId, after promotion/capacity enforcement
     * @param EventId $eventId
     * @return array<string, Crew> Modified crews keyed by crew key
     */
    private function updateAvailabilityStatuses(
        array $availableCrews,
        array $flotilla,
        EventId $eventId
    ): array {
        $selectedKeys = [];
        foreach ($flotilla['crewed_boats'] as $crewedBoat) {
            foreach ($crewedBoat['crews'] as $crew) {
                $selectedKeys[$crew->getKey()->toString()] = true;
            }
        }

        $modifiedCrews = [];
        foreach ($availableCrews as $crew) {
            $status = isset($selectedKeys[$crew->getKey()->toString()])
                ? AvailabilityStatus::SELECTED
                : AvailabilityStatus::NOT_SELECTED;
            $crew->setAvailability($eventId, $status);
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
     * Persist modified crew availability statuses (SELECTED or NOT_SELECTED) for the
     * next event to the database.
     *
     * Instead of saving entire crew entities (which would re-save all availability,
     * history, and whitelist data), we directly update only the single event's status
     * that changed. This dramatically reduces database operations.
     *
     * @param array<string, Crew> $modifiedCrews Modified crews keyed by crew key
     * @param EventId $eventId The event whose status was updated (the next event)
     */
    private function persistChanges(array $modifiedCrews, EventId $eventId): void
    {
        foreach ($modifiedCrews as $crew) {
            $this->crewRepository->updateAvailability(
                $crew->getKey(),
                $eventId,
                $crew->getAvailability($eventId)
            );
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
                    availability: 0, // Flex owners created for event (no prior availability)
                    commitment: 2,   // Available (willing to crew)
                    membership: 1,   // Club member (implied by boat ownership)
                    absence: 0       // No crew absence history
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
     * Sync crew assignment history from stored flotillas for past events.
     *
     * For each past event that has a flotilla:
     *   - Crews in crewed_boats  → assigned boat key
     *   - Crews in waitlist_crews → '' (registered but not assigned)
     *   - Unregistered crews      → no entry written
     *
     * Updates absence rank on each crew in-memory so selection uses correct ranks.
     *
     * @param Squad $squad All crews (mutated in place)
     * @return array<array{CrewKey, EventId, string}> Pending DB writes
     */
    private function syncCrewHistory(Squad $squad): array
    {
        $pastEventIds = $this->eventRepository->findPastEvents();
        if (empty($pastEventIds)) {
            return [];
        }

        $allCrews = $squad->all();
        $updates = [];
        $eventIdsWithFlotilla = [];

        foreach ($pastEventIds as $eventIdString) {
            $eventId = EventId::fromString($eventIdString);
            $flotilla = $this->seasonRepository->getFlotilla($eventId);
            if ($flotilla === null) {
                continue; // No flotilla data — skip (don't penalise crews)
            }

            $eventIdsWithFlotilla[] = $eventIdString;

            // Build map: crew_key => boat_key from flotilla
            $crewBoatMap = [];
            foreach ($flotilla['crewed_boats'] as $crewedBoat) {
                $boatKey = $crewedBoat['boat']['key'];
                foreach ($crewedBoat['crews'] as $crewData) {
                    $crewBoatMap[$crewData['key']] = $boatKey;
                }
            }
            // Waitlisted crews → '' (registered, not assigned)
            foreach ($flotilla['waitlist_crews'] as $crewData) {
                $crewBoatMap[$crewData['key']] ??= '';
            }

            // Update in-memory history for real DB crews only
            foreach ($allCrews as $crew) {
                $crewKeyStr = $crew->getKey()->toString();
                if (array_key_exists($crewKeyStr, $crewBoatMap)) {
                    $boatKey = $crewBoatMap[$crewKeyStr];
                    $crew->setHistory($eventId, $boatKey);
                    $updates[] = [$crew->getKey(), $eventId, $boatKey];
                }
                // No entry for crews not present in the flotilla (unregistered)
            }
        }

        // Recompute absence ranks (only counts events that had a flotilla)
        if (!empty($eventIdsWithFlotilla)) {
            $this->rankingService->updateCrewAbsenceRanks($allCrews, $eventIdsWithFlotilla);
        }

        return $updates;
    }

    /**
     * Enforce capacity constraints - remove lowest-ranked crews if boat capacity exceeded
     *
     * If a boat's capacity decreased since it was selected, remove lowest-ranked crews
     * first to fit the new capacity
     *
     * @param array $flotilla Flotilla structure
     * @param EventId $eventId Event being processed
     * @return array Flotilla with excess crews moved to waitlist
     */
    private function enforceCapacityConstraints(array $flotilla, EventId $eventId): array
    {
        foreach ($flotilla['crewed_boats'] as &$crewedBoat) {
            $boat = $crewedBoat['boat'];
            $maxBerths = $boat->getBerths($eventId);
            $assignedCount = count($crewedBoat['crews']);

            if ($assignedCount > $maxBerths) {
                // Sort crews by rank (descending - keep highest ranked)
                usort($crewedBoat['crews'], fn($a, $b) => $b->getRank() <=> $a->getRank());

                // Remove lowest-ranked crews until we fit capacity
                $toRemove = $assignedCount - $maxBerths;
                $removed = array_splice($crewedBoat['crews'], -$toRemove);

                // Move removed crews to waitlist
                $flotilla['waitlist_crews'] = array_merge($flotilla['waitlist_crews'], $removed);

                $this->logger->warning('season_update.capacity_reduced', [
                    'event_id' => $eventId->toString(),
                    'boat_key' => $boat->getKey()->toString(),
                    'crews_removed' => count($removed),
                ]);
            }
        }

        return $flotilla;
    }
}
