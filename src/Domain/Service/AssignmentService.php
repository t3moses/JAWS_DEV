<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Enum\AssignmentRule;
use App\Domain\Enum\SkillLevel;

/**
 * Assignment Service
 *
 * CRITICAL: This service contains the proven crew-to-boat assignment optimization algorithm.
 * The algorithm has been preserved character-for-character from the legacy system.
 *
 * Responsibilities:
 * - Optimize crew assignments to minimize rule violations
 * - 6 rules: ASSIST, WHITELIST, HIGH_SKILL, LOW_SKILL, PARTNER, REPEAT
 * - Greedy swapping algorithm with unlocked crew tracking
 * - Loss/gradient calculations for each rule
 *
 * DO NOT modify the core algorithm logic without extensive testing and comparison
 * with the legacy system to ensure identical behavior.
 */
class AssignmentService
{
    private const MAX_SKILL = 2;

    /** @var array<string, int> Loss values for crews */
    public array $losses = [];

    /** @var array<string, int> Gradient values for crews */
    public array $grads = [];

    /** @var array<string, mixed> Flotilla structure */
    private array $flotilla = [];

    /**
     * Main assignment optimization algorithm
     *
     * @param array<string, mixed> $flotilla Flotilla structure with 'crewed_boats'
     * @return array<string, mixed> Optimized flotilla
     */
    public function assign(array $flotilla): array
    {
        $this->flotilla = $flotilla;

        // Build the unlocked_crews array listing the keys of all crew objects in the flotilla
        $unlockedCrews = [];
        for ($i = 0; $i < count($this->flotilla['crewed_boats']); $i++) {
            $crewedBoat = $this->flotilla['crewed_boats'][$i];
            for ($j = 0; $j < count($crewedBoat['crews']); $j++) {
                $crew = $crewedBoat['crews'][$j];
                $unlockedCrews[] = $crew->getKey()->toString();
            }
        }

        // Lock high-skill crew on boats requiring assistance
        for ($i = 0; $i < count($this->flotilla['crewed_boats']); $i++) {
            $crewedBoat = $this->flotilla['crewed_boats'][$i];
            if ($crewedBoat['boat']->requiresAssistance()) {
                for ($j = 0; $j < count($crewedBoat['crews']); $j++) {
                    $crew = $crewedBoat['crews'][$j];
                    if ($crew->getSkill()->value === self::MAX_SKILL) {
                        // Remove this crew from the unlocked crews list
                        $key = array_search($crew->getKey()->toString(), $unlockedCrews);
                        if ($key !== false) {
                            unset($unlockedCrews[$key]);
                            $unlockedCrews = array_values($unlockedCrews); // Reindex the array
                        }
                        break; // Only remove one crew per boat
                    }
                }
            }
        }

        // Process each rule in priority order
        foreach (AssignmentRule::priorityOrder() as $rule) {
            while (count($unlockedCrews) > 1) {
                $this->losses = []; // reset the losses and grads arrays
                $this->grads = [];

                // Make lists of loss and grad values for all unlocked crews
                for ($i = 0; $i < count($this->flotilla['crewed_boats']); $i++) {
                    $crewedBoat = $this->flotilla['crewed_boats'][$i];
                    for ($j = 0; $j < count($crewedBoat['crews']); $j++) {
                        $crew = $crewedBoat['crews'][$j];
                        if (in_array($crew->getKey()->toString(), $unlockedCrews)) {
                            $this->losses[$crew->getKey()->toString()] = $this->crewLoss($rule, $crew, $crewedBoat);
                            $this->grads[$crew->getKey()->toString()] = $this->crewGrad($rule, $crew, $crewedBoat);
                        }
                    }
                }

                // Order the lists (highest first)
                arsort($this->losses);
                arsort($this->grads);

                if (array_values($this->losses)[0] === 0) {
                    break; // Move to the next rule
                }
                if (array_values($this->grads)[0] === 0) {
                    break; // Move to the next rule
                }

                $topLossCrew = $this->crewFromKey(array_keys($this->losses)[0]);
                $topGradCrew = $this->bestSwap($this->losses, $this->grads, $rule);

                if ($topGradCrew === null) {
                    break; // Valid swap not found, move to next rule
                }

                // Find the crew and boat indices and objects corresponding to the top loss and grad crews
                $topLossBoatIndex = null;
                $topLossBoat = null;
                $topLossCrewIndex = null;
                $topLossCrewCopy = null;
                $topGradBoatIndex = null;
                $topGradBoat = null;
                $topGradCrewIndex = null;
                $topGradCrewCopy = null;

                for ($i = 0; $i < count($this->flotilla['crewed_boats']); $i++) {
                    $crewedBoat = $this->flotilla['crewed_boats'][$i];
                    for ($j = 0; $j < count($crewedBoat['crews']); $j++) {
                        $cbCrew = $crewedBoat['crews'][$j];
                        if ($cbCrew->getKey()->equals($topLossCrew->getKey())) {
                            $topLossBoatIndex = $i;
                            $topLossBoat = $crewedBoat['boat'];
                            $topLossCrewIndex = $j;
                            $topLossCrewCopy = clone $cbCrew;
                        }
                        if ($cbCrew->getKey()->equals($topGradCrew->getKey())) {
                            $topGradBoatIndex = $i;
                            $topGradBoat = $crewedBoat['boat'];
                            $topGradCrewIndex = $j;
                            $topGradCrewCopy = clone $cbCrew;
                        }
                    }
                }

                // Perform the swap
                $this->flotilla['crewed_boats'][$topLossBoatIndex]['crews'][$topLossCrewIndex] = $topGradCrewCopy;
                $this->flotilla['crewed_boats'][$topGradBoatIndex]['crews'][$topGradCrewIndex] = $topLossCrewCopy;
                array_splice($unlockedCrews, array_search($topGradCrewCopy->getKey()->toString(), $unlockedCrews), 1);
            }
        }

        return $this->flotilla;
    }

    /**
     * Calculate loss for a crew under a specific rule
     *
     * @param AssignmentRule $rule The rule to evaluate
     * @param Crew $crew The crew member
     * @param array<string, mixed> $crewedBoat The crewed boat structure
     * @return int Loss value (higher = worse violation)
     */
    public function crewLoss(AssignmentRule $rule, Crew $crew, array $crewedBoat): int
    {
        if (!$this->onboard($crew, $crewedBoat)) {
            throw new \RuntimeException("Trying to get loss for a crew that is not onboard");
        }

        if ($rule === AssignmentRule::ASSIST) {
            if (!$crewedBoat['boat']->requiresAssistance()) {
                return 0;
            } else {
                // Check if boat already has a high-skill crew
                for ($i = 0; $i < count($crewedBoat['crews']); $i++) {
                    $cbCrew = $crewedBoat['crews'][$i];
                    if ($cbCrew->getSkill()->value === self::MAX_SKILL) {
                        return 0;
                    }
                }
                return self::MAX_SKILL - $crew->getSkill()->value;
            }
        } elseif ($rule === AssignmentRule::WHITELIST) {
            if ($crew->isWhitelisted($crewedBoat['boat']->getKey())) {
                return 0;
            } else {
                return 1;
            }
        } elseif ($rule === AssignmentRule::HIGH_SKILL) {
            if ($this->skillSpread($crewedBoat) === self::MAX_SKILL) {
                if ($crew->getSkill()->value === self::MAX_SKILL) {
                    return 1; // high spread, skill is max, so yes to high skill loss
                } else {
                    return 0; // high spread, but skill is 0 or middle, so no to high skill loss
                }
            }
            return 0; // not even high spread, so no to high skill loss
        } elseif ($rule === AssignmentRule::LOW_SKILL) {
            if ($this->skillSpread($crewedBoat) === self::MAX_SKILL) {
                if ($crew->getSkill()->value === 0) {
                    return 1; // high spread, skill is 0 so yes to low skill loss
                } else {
                    return 0; // high spread, but skill is high or middle, so no to low skill loss
                }
            }
            return 0; // not even high spread, so no to low skill loss
        } elseif ($rule === AssignmentRule::PARTNER) {
            // Check if partner is on the same boat (violation)
            for ($i = 0; $i < count($crewedBoat['crews']); $i++) {
                $cbCrew = $crewedBoat['crews'][$i];
                if (
                    $crew->getPartnerKey() !== null &&
                    $cbCrew->getKey()->equals($crew->getPartnerKey())
                ) {
                    return 1;
                }
            }
            return 0;
        } elseif ($rule === AssignmentRule::REPEAT) {
            $cbBoat = $crewedBoat['boat'];
            for ($i = 0; $i < count($crewedBoat['crews']); $i++) {
                $cbCrew = $crewedBoat['crews'][$i];
                if ($crew->getKey()->equals($cbCrew->getKey())) {
                    $history = $cbCrew->getAllHistory();
                    return array_count_values($history)[$cbBoat->getKey()->toString()] ?? 0;
                }
            }
            return 0;
        }

        return 0;
    }

    /**
     * Calculate gradient (mitigation capacity) for a crew under a specific rule
     *
     * @param AssignmentRule $rule The rule to evaluate
     * @param Crew $crew The crew member
     * @param array<string, mixed> $crewedBoat The crewed boat structure (not used for grad)
     * @return int Gradient value (higher = better candidate for swap)
     */
    private function crewGrad(AssignmentRule $rule, Crew $crew, array $crewedBoat): int
    {
        if ($rule === AssignmentRule::ASSIST) {
            return $crew->getSkill()->value;
        } elseif ($rule === AssignmentRule::WHITELIST) {
            return count($crew->getWhitelist());
        } elseif ($rule === AssignmentRule::HIGH_SKILL) {
            if ($crew->getSkill()->value === self::MAX_SKILL) {
                return 0; // skill is max so no to grad
            } else {
                return 1; // skill is low or middle, so yes to high skill grad
            }
        } elseif ($rule === AssignmentRule::LOW_SKILL) {
            if ($crew->getSkill()->value === 0) {
                return 0; // skill is 0, so no to low skill grad
            } else {
                return 1; // skill is high or middle, so yes to low skill grad
            }
        } elseif ($rule === AssignmentRule::PARTNER) {
            if ($crew->getPartnerKey() === null) {
                return 1;
            } else {
                return 0;
            }
        } elseif ($rule === AssignmentRule::REPEAT) {
            $history = $crew->getAllHistory();
            $emptyCount = 0;
            foreach ($history as $boatKey) {
                if ($boatKey === '') {
                    $emptyCount++;
                }
            }
            return $emptyCount;
        }

        return 0;
    }

    /**
     * Find the best crew to swap with based on gradient values
     *
     * @param array<string, int> $losses
     * @param array<string, int> $grads
     * @param AssignmentRule $rule
     * @return Crew|null Best swap candidate or null if none found
     */
    public function bestSwap(array $losses, array $grads, AssignmentRule $rule): ?Crew
    {
        // Find the crew and boat corresponding to the highest loss
        $aCrewKey = array_keys($losses)[0];
        $aCrew = $this->crewFromKey($aCrewKey);
        $aCrewedBoat = $this->crewedBoatFromKey($aCrewKey);

        if ($aCrewedBoat === null) {
            return null; // Crew not found in flotilla
        } else {
            $aBoat = $aCrewedBoat['boat'];
        }

        // Now go through the grad array to find the best swap candidate
        foreach ($grads as $bCrewKey => $bGrad) {
            // Find the candidate crew and its boat
            $bCrewedBoat = $this->crewedBoatFromKey($bCrewKey);
            if ($bCrewedBoat === null) {
                return null; // Replacement crew not found in flotilla
            } else {
                $bBoat = $bCrewedBoat['boat'];
            }

            if ($aBoat->getKey()->equals($bBoat->getKey())) {
                continue; // Same boat, try next candidate
            }

            // If the candidate crew (b_crew) does not reduce loss, try the next candidate
            // Otherwise, return the candidate crew
            if ($this->badSwap($rule, $aCrewKey, $bCrewKey)) {
                continue;
            } else {
                return $this->crewFromKey($bCrewKey); // Valid swap found
            }
        }


        return null; // No valid swap found
    }

    /**
     * Check if swapping two crews would increase loss under any rule up to and including the current rule
     *
     * @param AssignmentRule $rule Current rule
     * @param string $aCrewKey First crew key
     * @param string $bCrewKey Second crew key
     * @return bool True if swap is bad (increases loss)
     */
    private function badSwap(AssignmentRule $rule, string $aCrewKey, string $bCrewKey): bool
    {
        $rules = AssignmentRule::priorityOrder();
        $ruleIndex = array_search($rule, $rules, true);

        for ($i = $ruleIndex; $i >= 0; $i--) {
            $ruleName = $rules[$i];
            if ($this->badRuleSwap($ruleName, $aCrewKey, $bCrewKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if swapping two crews would increase loss under a specific rule
     *
     * @param AssignmentRule $rule The rule to check
     * @param string $aCrewKey First crew key
     * @param string $bCrewKey Second crew key
     * @return bool True if swap increases loss for this rule
     */
    private function badRuleSwap(AssignmentRule $rule, string $aCrewKey, string $bCrewKey): bool
    {
        $aCrew = $this->crewFromKey($aCrewKey);
        $bCrew = $this->crewFromKey($bCrewKey);

        $aCrewedBoatBefore = $this->crewedBoatFromKey($aCrewKey);
        $bCrewedBoatBefore = $this->crewedBoatFromKey($bCrewKey);

        $aBoatLossBefore = $this->crewLoss($rule, $aCrew, $aCrewedBoatBefore);
        $bBoatLossBefore = $this->crewLoss($rule, $bCrew, $bCrewedBoatBefore);

        $aCrewedBoatAfter = $this->replaceCrew($aCrew, $bCrew, $aCrewedBoatBefore);
        $bCrewedBoatAfter = $this->replaceCrew($bCrew, $aCrew, $bCrewedBoatBefore);

        $aBoatLossAfter = $this->crewLoss($rule, $bCrew, $aCrewedBoatAfter);
        $bBoatLossAfter = $this->crewLoss($rule, $aCrew, $bCrewedBoatAfter);

        if ($aBoatLossAfter > $aBoatLossBefore || $bBoatLossAfter > $bBoatLossBefore) {
            return true;
        }

        return false;
    }

    /**
     * Calculate skill spread on a boat (max skill - min skill)
     *
     * @param array<string, mixed> $crewedBoat
     * @return int Skill spread (0-2)
     */
    private function skillSpread(array $crewedBoat): int
    {
        $topSkill = 0;
        $bottomSkill = self::MAX_SKILL;

        for ($i = 0; $i < count($crewedBoat['crews']); $i++) {
            $cbCrew = $crewedBoat['crews'][$i];
            $topSkill = max($topSkill, $cbCrew->getSkill()->value);
            $bottomSkill = min($bottomSkill, $cbCrew->getSkill()->value);
        }

        return $topSkill - $bottomSkill; // skill spread
    }

    /**
     * Check if a crew is onboard a boat
     *
     * @param Crew $crew
     * @param array<string, mixed> $crewedBoat
     * @return bool
     */
    private function onboard(Crew $crew, array $crewedBoat): bool
    {
        foreach ($crewedBoat['crews'] as $cbCrew) {
            if ($cbCrew->getKey()->equals($crew->getKey())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get crew object from key
     *
     * @param string $crewKey
     * @return Crew
     */
    public function crewFromKey(string $crewKey): ?Crew
    {
        foreach ($this->flotilla['crewed_boats'] as $crewedBoat) {
            foreach ($crewedBoat['crews'] as $cbCrew) {
                if ($cbCrew->getKey()->toString() === $crewKey) {
                    return $cbCrew;
                }
            }
        }
        return null; // If crew not found in flotilla
    }

    /**
     * Get crewed boat structure for a crew
     *
     * @param string $crewKey
     * @return array<string, mixed>|null
     */
    public function crewedBoatFromKey(string $crewKey): ?array
    {
        foreach ($this->flotilla['crewed_boats'] as $crewedBoat) {
            foreach ($crewedBoat['crews'] as $cbCrew) {
                if ($cbCrew->getKey()->toString() === $crewKey) {
                    return $crewedBoat;
                }
            }
        }
        return null; // If crew not found in flotilla
    }

    /**
     * Replace a crew with another in a crewed boat structure
     *
     * @param Crew $aCrew Crew to replace
     * @param Crew $bCrew Replacement crew
     * @param array<string, mixed> $crewedBoat Crewed boat structure
     * @return array<string, mixed> Updated crewed boat structure
     */
    private function replaceCrew(Crew $aCrew, Crew $bCrew, array $crewedBoat): array
    {
        for ($i = 0; $i <= count($crewedBoat['crews']); $i++) {
            if ($crewedBoat['crews'][$i]->getKey()->equals($aCrew->getKey())) {
                $crewedBoat['crews'][$i] = $bCrew;
                return $crewedBoat;
            }
        }

        // $aCrew is not onboard $crewedBoat
        throw new \RuntimeException("Can't find crew to replace");
    }
}
