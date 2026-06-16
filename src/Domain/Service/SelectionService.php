<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\Rank;

/**
 * Selection Service
 *
 * CRITICAL: This service contains the proven selection and ranking algorithm.
 * The algorithm has been preserved character-for-character from the legacy system.
 *
 * Responsibilities:
 * - Deterministic shuffle using CRC32 seeding
 * - Lexicographic rank comparison
 * - Bubble sort based on multi-dimensional ranks
 * - Capacity matching (3 cases: too few crews, too many crews, perfect fit)
 *
 * DO NOT modify the core algorithm logic without extensive testing and comparison
 * with the legacy system to ensure identical behavior.
 */
class SelectionService
{
    private EventId $eventId;

    /** @var array<Boat> */
    private array $selectedBoats = [];

    /** @var array<Crew> */
    private array $selectedCrews = [];

    /** @var array<Boat> */
    private array $waitlistBoats = [];

    /** @var array<Crew> */
    private array $waitlistCrews = [];

    /**
     * Main selection algorithm
     *
     * @param array<Boat> $boats Available boats for the event
     * @param array<Crew> $crews Available crews for the event
     * @param EventId $eventId The event identifier
     */
    public function select(array $boats, array $crews, EventId $eventId): void
    {
        $this->eventId = $eventId;

        // Shuffle with deterministic seeding (event_id hash)
        $shuffledBoats = $this->mix($boats, $eventId->toString());
        $sortedBoats = $this->bubble($shuffledBoats);

        $shuffledCrews = $this->mix($crews, $eventId->toString());
        $sortedCrews = $this->bubble($shuffledCrews);
/*
        self::trace("Event: " . $eventId->toString() . "\n");
        foreach ($shuffledBoats as $boat) {
            self::trace("boat: " . $boat->getId() . " Rank: " . $boat->getRank() . "\n");
        }
        foreach ($shuffledCrews as $crew) {
            self::trace("crew: " . $crew->getId() . " Rank: " . $crew->getRank() . "\n");
        }
        self::trace("\n\n");
*/
        // Cut boats or crews to fit, then distribute
        $this->cut($sortedBoats, $sortedCrews);
    }

    /**
     * Get boats selected for the event
     *
     * @return array<Boat>
     */
    public function getSelectedBoats(): array
    {
        return $this->selectedBoats;
    }

    /**
     * Get crews selected for the event
     *
     * @return array<Crew>
     */
    public function getSelectedCrews(): array
    {
        return $this->selectedCrews;
    }

    /**
     * Get boats on waitlist
     *
     * @return array<Boat>
     */
    public function getWaitlistBoats(): array
    {
        return $this->waitlistBoats;
    }

    /**
     * Get crews on waitlist
     *
     * @return array<Crew>
     */
    public function getWaitlistCrews(): array
    {
        return $this->waitlistCrews;
    }

    /**
     * Calculate minimum berths required
     *
     * @param array<Boat> $boats
     * @return int
     */
    private function getMinBerths(array $boats): int
    {
        $minBerths = 0;
        foreach ($boats as $boat) {
            $minBerths += $boat->getMinBerths();
        }
        return $minBerths;
    }

    /**
     * Calculate maximum berths available
     *
     * @param array<Boat> $boats
     * @return int
     */
    private function getMaxBerths(array $boats): int
    {
        $maxBerths = 0;
        foreach ($boats as $boat) {
            $maxBerths += $boat->getBerths($this->eventId);
        }
        return $maxBerths;
    }

    /**
     * Deterministic shuffle using CRC32 seeding
     *
     * CRITICAL: This ensures the same event_id always produces the same shuffle order.
     * This is essential for reproducible results and user trust.
     *
     * @param array<Boat|Crew> $list
     * @param string|null $seed
     * @return array<Boat|Crew>
     */

    private function mix(array $list, ?string $seed): array
    {
        if ($seed !== null) {
            mt_srand(crc32($seed));
        }

        shuffle($list);
        return $list;
    }
/*
    private function mix(array $list, ?string $seed): array
    {
        if ($seed !== null) {
            mt_srand(crc32($seed));
        }

        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        return $list;
    }
*/
    /**
     * Lexicographic rank comparison
     *
     * CRITICAL: Compares ranks dimension by dimension (left to right).
     * Returns true if rank1 < rank2 (rank1 has LOWER priority).
     *
     * @param Rank $rank1
     * @param Rank $rank2
     * @return bool
     */

    private function isLess(Rank $rank1, Rank $rank2): bool
    {
        $values1 = $rank1->toArray();
        $values2 = $rank2->toArray();

        $dimensions = count($values1);
        for ($i = 0; $i < $dimensions; $i++) {
            if ((int)$values1[$i] < (int)$values2[$i]) {
                return true;
            } elseif ((int)$values1[$i] > (int)$values2[$i]) {
                return false;
            }
        }

        return false; // The input ranks are equal
    }

    /**
     * Bubble sort based on rank (highest to lowest)
     *
     * CRITICAL: Uses optimized bubble sort with early termination.
     * Sorts in descending rank order (highest rank = highest priority first).
     *
     * @param array<Boat|Crew> $list
     * @return array<Boat|Crew>
     */
    private function bubble(array $list): array
    {
        $n = count($list);

        // Traverse through all array elements
        for ($i = 0; $i < $n; $i++) {
            $swapped = false;

            // Last i elements are already in place
            for ($j = 0; $j < $n - $i - 1; $j++) {
                // Traverse the list from 0 to n-i-1. Swap if the element
                // found is less than the next element
                if ($this->isLess($list[$j]->getRank(), $list[$j + 1]->getRank())) {
                    $temp = $list[$j];
                    $list[$j] = $list[$j + 1];
                    $list[$j + 1] = $temp;
                    $swapped = true;
                }
            }

            // If no two elements were swapped by inner loop, then break
            if ($swapped == false) {
                break;
            }
        }

/*
        self::trace("Sorted List:\n");
        foreach ($list as $entry) {
            self::trace("    " . $entry->getId() . "\n");
        }
        self::trace("\n\n");
*/
        return $list;
    }

    /**
     * Capacity matching dispatcher
     *
     * Determines which case to apply based on crew count vs berth capacity.
     *
     * @param array<Boat> $boats Sorted boats
     * @param array<Crew> $crews Sorted crews
     */
    private function cut(array $boats, array $crews): void
    {
        // Special case: If there are no boats, ignore all crews
        if (empty($boats)) {
            $this->selectedBoats = [];
            $this->selectedCrews = [];
            $this->waitlistBoats = [];
            $this->waitlistCrews = [];
            return;
        }

        $minBerths = $this->getMinBerths($boats);
        $maxBerths = $this->getMaxBerths($boats);

        if (count($crews) < $minBerths) {
            $this->case1($boats, $crews);
        } elseif (count($crews) > $maxBerths) {
/*
            foreach ($crews as $crew) {
                $this->trace("Crew: " . $crew->getId() . " Rank: " . implode(",", $crew->getRank()->toArray()) . "\n");
            }
*/
            $this->case2($boats, $crews);
        } else {
            $this->case3($boats, $crews);
        }
    }

    /**
     * Case 1: Too few crews
     *
     * The minimum number of crews required by owners exceeds the actual number
     * of available crews. We need to cut boats, starting with the lowest ranked boat.
     *
     * @param array<Boat> $boats
     * @param array<Crew> $crews
     */
    private function case1(array $boats, array $crews): void
    {
        // Set all boats to minimum berths
        foreach ($boats as $boat) {
            $boat->occupied_berths = $boat->getMinBerths();
        }

        $allBerths = $this->getMinBerths($boats);
        $crewCount = count($crews);
        $waitlistBoats = [];

        // Cut boats from the end (lowest rank = lowest priority) until we have enough berths
        while ($allBerths > $crewCount) {
            $cutBoat = array_pop($boats);
            $allBerths -= $cutBoat->getMinBerths();
            $waitlistBoats[] = $cutBoat;
        }

        if ($allBerths === $crewCount) {
            // Perfect fit after cutting boats
            $this->selectedBoats = $boats;
            $this->selectedCrews = $crews;
            $this->waitlistBoats = $waitlistBoats;
            $this->waitlistCrews = [];
            return;
        }

        // After cutting boats, we still have fewer berths than crews
        // Check if boats have flexible capacity to accommodate more crews
        $maxBerthsRemaining = $this->getMaxBerths($boats);

        if ($crewCount <= $maxBerthsRemaining) {
            // Boats have flexible capacity - use case 3 to distribute optimally
            $this->case3($boats, $crews);
        } else {
            // Even with maximum berths, we can't fit all crews
            // This should not happen in normal flow, but handle gracefully
            // by executing case 2 and cutting crew.
            $this->case2($boats, $crews);
        }

        // Preserve boats cut above — case3/case2 reset waitlistBoats to []
        $this->waitlistBoats = array_merge($this->waitlistBoats, $waitlistBoats);
    }

    /**
     * Case 2: Too many crews
     *
     * The actual number of available crews exceeds the maximum number of available
     * berths. We need to cut crews, starting with the lowest ranked crew.
     *
     * @param array<Boat> $boats
     * @param array<Crew> $crews
     */
    private function case2(array $boats, array $crews): void
    {
        // Set all boats to maximum offered berths
        foreach ($boats as $boat) {
            $boat->occupied_berths = $boat->getBerths($this->eventId);
        }

        $allBerths = $this->getMaxBerths($boats);
        $excessCrews = count($crews) - $allBerths;

        // Cut crews from the end (lowest rank = lowest priority)
        $waitlistCrews = array_slice($crews, -$excessCrews);
        $crews = array_slice($crews, 0, -$excessCrews);
        $this->selectedBoats = $boats;
        $this->selectedCrews = $crews;
        $this->waitlistBoats = [];
        $this->waitlistCrews = $waitlistCrews;
    }

    /**
     * Case 3: Perfect fit
     *
     * The actual number of available crews can be accommodated by the actual
     * number of available berths. No cuts are required.
     * Distribute crews optimally across boats.
     *
     * @param array<Boat> $boats
     * @param array<Crew> $crews
     */
    private function case3(array $boats, array $crews): void
    {
        // Start with minimum berths for all boats
        foreach ($boats as $boat) {
            $boat->occupied_berths = $boat->getMinBerths();
        }

        $allBerths = $this->getMinBerths($boats);
        $crewCount = count($crews);

        // Incrementally add berths to boats with the most available space
        while ($allBerths < $crewCount) {
            $biggestSpace = 0;
            $augmentedBoat = null;

            foreach ($boats as $boat) {
                $boatSpace = $boat->getBerths($this->eventId) - $boat->occupied_berths;
                if ($boatSpace > $biggestSpace) {
                    $biggestSpace = $boatSpace;
                    $augmentedBoat = $boat;
                }
            }

            if ($augmentedBoat !== null) {
                $augmentedBoat->occupied_berths++;
                $allBerths++;
            }
        }
        $this->selectedBoats = $boats;
        $this->selectedCrews = $crews;
        $this->waitlistBoats = [];
        $this->waitlistCrews = [];
    }
/*
    private function trace(string $contents): void
    {
        $fileName = __DIR__ . "/../../../trace.txt";
        file_put_contents($fileName, $contents, FILE_APPEND | LOCK_EX);
    }
*/
}
