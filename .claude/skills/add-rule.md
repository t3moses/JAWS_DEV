# Add Assignment Rule Skill

Guide for adding a new optimization rule to the crew-to-boat assignment algorithm in JAWS.

## Overview

The AssignmentService optimizes crew-to-boat assignments by minimizing violations of 6 rules:

1. **ASSIST**: Boats requiring assistance get appropriate crew
2. **WHITELIST**: Crew assigned to boats they've whitelisted
3. **HIGH_SKILL**: Balance high-skill crew distribution
4. **LOW_SKILL**: Balance low-skill crew distribution
5. **PARTNER**: Keep requested partnerships together
6. **REPEAT**: Minimize crew repeating same boat

## Process

The optimizer uses a greedy approach:
1. Calculate **Loss** (violation severity) for each crew on current boat
2. Calculate **Grad** (potential reduction) if crew swaps to another boat
3. Identify highest-loss crew
4. Find best-grad swap candidate
5. Perform swap if it improves overall violations
6. Lock swapped crew to prevent thrashing

## Steps to Add New Rule

### 1. Add Enum Case

Edit `src/Domain/Enum/AssignmentRule.php`:

```php
enum AssignmentRule: int
{
    case ASSIST = 0;
    case WHITELIST = 1;
    case HIGH_SKILL = 2;
    case LOW_SKILL = 3;
    case PARTNER = 4;
    case REPEAT = 5;
    case YOUR_NEW_RULE = 6;  // Add new rule
}
```

### 2. Implement Loss Calculation

Edit `src/Domain/Service/AssignmentService.php`, add handling to `crewLoss()`:

```php
public function crewLoss(
    AssignmentRule $rule,
    Crew $crew,
    array $crewedBoat
): int {
    if ($rule === AssignmentRule::YOUR_NEW_RULE) {
        // Example custom loss calculation:
        return $this->yourNewRuleLoss($crew, $crewedBoat);
    }

    // existing branches...
}

private function yourNewRuleLoss(Crew $crew, array $crewedBoat): int
{
    // Return integer severity (0 = no violation, higher = worse)
    return 0;
}
```

### 3. Implement Gradient Calculation

Add handling to `crewGrad()` in `AssignmentService.php`:

```php
private function crewGrad(
    AssignmentRule $rule,
    Crew $crew,
    array $crewedBoat
): int {
    if ($rule === AssignmentRule::YOUR_NEW_RULE) {
        return $this->yourNewRuleGrad($crew, $crewedBoat);
    }

    // existing branches...
}

private function yourNewRuleGrad(Crew $crew, array $crewedBoat): int
{
    // Return integer mitigation capacity (higher = better swap candidate)
    return 0;
}
```

### 4. Add Rule to Priority Order

Update `AssignmentRule::priorityOrder()` in `AssignmentRule.php`:

```php
public static function priorityOrder(): array
{
    return [
        AssignmentRule::ASSIST,      // Highest priority
        AssignmentRule::WHITELIST,
        AssignmentRule::YOUR_NEW_RULE,  // Add your rule in priority order
        AssignmentRule::HIGH_SKILL,
        AssignmentRule::LOW_SKILL,
        AssignmentRule::PARTNER,
        AssignmentRule::REPEAT,      // Lowest priority
    ];
}
```

### 5. Add Helper Methods (If Needed)

```php
/**
 * Count how many times crew was on this boat recently
 */
private function countRecentAssignments(Crew $crew, Boat $boat, int $recentEvents): int
{
    $history = $crew->getHistory();
    $count = 0;

    // Check last N events
    $events = array_slice(array_keys($history), -$recentEvents);

    foreach ($events as $eventId) {
        if ($history[$eventId] === $boat->getKey()->getValue()) {
            $count++;
        }
    }

    return $count;
}
```

### 6. Write Tests

Create tests in `tests/Unit/Domain/Service/AssignmentServiceTest.php`:

```php
public function testYourNewRuleLoss(): void
{
    $service = new AssignmentService();
    $crew = new Crew(...);
    $boat = new Boat(...);

    // Set up test conditions
    $crew->setHistory(['event1' => 'boat1', 'event2' => 'boat1']);

    // Calculate loss
    $loss = $service->crewLoss(
        AssignmentRule::YOUR_NEW_RULE,
        $crew,
        ['boat' => $boat, 'crews' => [$crew]]
    );

    // Verify expected loss
    $this->assertGreaterThan(0, $loss);
}

public function testYourNewRuleGrad(): void
{
    // Test gradient calculation
    // Verify that swapping improves the situation
}

public function testOptimizationWithNewRule(): void
{
    // Integration test
    // Verify assignments are improved when rule is applied
}
```

### 7. Update Documentation

Update `CLAUDE.md` in the "Assignment Optimization Algorithm" section:

```markdown
## Assignment Optimization Algorithm

**Process:**

2. **Iterate through 7 rules** in priority order (ASSIST, WHITELIST, YOUR_NEW_RULE, HIGH_SKILL, LOW_SKILL, PARTNER, REPEAT)
```

## Example: Adding "Balanced Experience" Rule

```php
// 1. Add enum
enum AssignmentRule: int
{
    // ... existing rules
    case BALANCED_EXPERIENCE = 6;
}

// 2. Implement loss calculation
private function balancedExperienceLoss(Crew $crew, Boat $boat, array $context): float
{
    $boatCrews = $context['current_assignments'][$boat->getKey()->getValue()] ?? [];

    // Calculate experience variance on this boat
    $experiences = array_map(fn($c) => $c->getYearsExperience(), $boatCrews);
    $avgExperience = array_sum($experiences) / count($experiences);

    // Penalize boats with imbalanced experience
    $variance = 0;
    foreach ($experiences as $exp) {
        $variance += pow($exp - $avgExperience, 2);
    }

    return sqrt($variance / count($experiences));  // Standard deviation
}

// 3. Implement gradient
private function balancedExperienceGrad(
    Crew $crew,
    Boat $fromBoat,
    Boat $toBoat,
    array $context
): float {
    $currentLoss = $this->balancedExperienceLoss($crew, $fromBoat, $context);
    $potentialLoss = $this->balancedExperienceLoss($crew, $toBoat, $context);

    return $currentLoss - $potentialLoss;
}

// 4. Add to priority order
public static function priorityOrder(): array
{
    return [
        AssignmentRule::ASSIST,
        AssignmentRule::WHITELIST,
        AssignmentRule::BALANCED_EXPERIENCE,
        AssignmentRule::HIGH_SKILL,
        AssignmentRule::LOW_SKILL,
        AssignmentRule::PARTNER,
        AssignmentRule::REPEAT,
    ];
}
```

## Important Considerations

### Rule Priority

Rules earlier in the priority order are optimized first. Place critical rules first:
- Safety-critical rules (e.g., ASSIST) → High priority
- User preferences (e.g., WHITELIST, PARTNER) → Medium-high priority
- Optimization rules (e.g., skill balance) → Medium priority
- Nice-to-have rules (e.g., variety) → Lower priority

### Loss vs Gradient

- **Loss**: How bad is the current situation?
  - 0 = perfect (no violation)
  - Higher values = worse violations

- **Gradient**: How much would swapping improve things?
  - Positive = improvement
  - Higher values = better swap candidate

### Swap Validation

The `badSwap()` method checks if a swap is valid. You may need to add validation logic:

```php
private function badSwap(AssignmentRule $rule, string $aCrewKey, string $bCrewKey): bool
{
    // Existing validations...

    // Add your validation if needed
    if ($this->violatesYourNewRule($crew, $toBoat)) {
        return true;  // Reject this swap
    }

    return false;
}
```

### Context

The current implementation does not pass a generic `$context` object through `crewLoss()` and `crewGrad()`. If your rule needs additional data, derive it from crew/boat history and flotilla state already available in `AssignmentService`.

```php
$context = [
    'current_assignments' => $assignments,
    'event_id' => $eventId,
    'recent_events' => 3,
    'your_custom_data' => $data,
];
```

## Testing Strategy

1. **Unit Tests**: Test loss/grad calculations in isolation
2. **Integration Tests**: Test full optimization with your rule
3. **Regression Tests**: Ensure existing rules still work
4. **Real Data Tests**: Test with historical data to verify improvements

## Performance Considerations

- Keep calculations efficient (O(1) or O(n) ideally)
- Avoid nested loops in loss/grad calculations
- Cache intermediate results if needed
- Profile performance with large datasets

## Checklist

- [ ] Enum case added to `AssignmentRule`
- [ ] Loss calculation implemented
- [ ] Gradient calculation implemented
- [ ] Rule added to priority order
- [ ] Helper methods added (if needed)
- [ ] Swap validation updated (if needed)
- [ ] Tests written and passing
- [ ] Documentation updated
- [ ] Real-world testing completed
- [ ] Performance profiled

## Critical Warning

⚠️ **PRESERVE BUSINESS LOGIC**

The Assignment algorithm is a **CRITICAL ALGORITHM** preserved from the legacy system. Changes must:

1. Not break existing functionality
2. Maintain deterministic behavior
3. Be thoroughly tested
4. Get stakeholder approval

## References

- **AssignmentService:** `src/Domain/Service/AssignmentService.php` ⚠️ CRITICAL
- **AssignmentRule Enum:** `src/Domain/Enum/AssignmentRule.php`
- **Tests:** `tests/Unit/Domain/Service/AssignmentServiceTest.php`
- **Documentation:** `CLAUDE.md` - "Assignment Optimization Algorithm"
