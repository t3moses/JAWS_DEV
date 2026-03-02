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
enum AssignmentRule: string
{
    case ASSIST = 'assist';
    case WHITELIST = 'whitelist';
    case HIGH_SKILL = 'high_skill';
    case LOW_SKILL = 'low_skill';
    case PARTNER = 'partner';
    case REPEAT = 'repeat';
    case YOUR_NEW_RULE = 'your_new_rule';  // Add new rule
}
```

### 2. Implement Loss Calculation

Edit `src/Domain/Service/AssignmentService.php`, add to `crew_loss()` method:

```php
private function crew_loss(
    Crew $crew,
    Boat $boat,
    AssignmentRule $rule,
    array $context
): float {
    return match ($rule) {
        // Existing rules...
        AssignmentRule::ASSIST => $this->assistLoss($crew, $boat),
        AssignmentRule::WHITELIST => $this->whitelistLoss($crew, $boat),
        // ... other rules

        // NEW: Your new rule
        AssignmentRule::YOUR_NEW_RULE => $this->yourNewRuleLoss($crew, $boat, $context),
    };
}

/**
 * Calculate loss for your new rule
 *
 * @return float Violation severity (0 = no violation, higher = worse)
 */
private function yourNewRuleLoss(Crew $crew, Boat $boat, array $context): float
{
    // Implement your loss calculation logic
    // Return 0 if no violation
    // Return higher values for worse violations

    // Example: Penalize if crew has been on this boat recently
    $recentEvents = $context['recent_events'] ?? 2;
    $timesOnBoat = $this->countRecentAssignments($crew, $boat, $recentEvents);

    return $timesOnBoat * 10.0;  // Loss = 10 per repeat assignment
}
```

### 3. Implement Gradient Calculation

Add to `crew_grad()` method in `AssignmentService.php`:

```php
private function crew_grad(
    Crew $crew,
    Boat $fromBoat,
    Boat $toBoat,
    AssignmentRule $rule,
    array $context
): float {
    return match ($rule) {
        // Existing rules...
        AssignmentRule::ASSIST => $this->assistGrad($crew, $fromBoat, $toBoat),
        // ... other rules

        // NEW: Your new rule
        AssignmentRule::YOUR_NEW_RULE => $this->yourNewRuleGrad(
            $crew,
            $fromBoat,
            $toBoat,
            $context
        ),
    };
}

/**
 * Calculate gradient (improvement potential) for your new rule
 *
 * @return float Potential reduction in violations (higher = better swap)
 */
private function yourNewRuleGrad(
    Crew $crew,
    Boat $fromBoat,
    Boat $toBoat,
    array $context
): float {
    // Calculate current loss
    $currentLoss = $this->yourNewRuleLoss($crew, $fromBoat, $context);

    // Calculate potential loss after swap
    $potentialLoss = $this->yourNewRuleLoss($crew, $toBoat, $context);

    // Gradient = reduction in loss (positive = improvement)
    return $currentLoss - $potentialLoss;
}
```

### 4. Add Rule to Priority Order

Edit the `assign()` method in `AssignmentService.php`:

```php
public function assign(Squad $squad, Fleet $fleet, EventId $eventId): void
{
    // Define rule priority order (higher priority = optimized first)
    $rules = [
        AssignmentRule::ASSIST,      // Highest priority
        AssignmentRule::WHITELIST,
        AssignmentRule::YOUR_NEW_RULE,  // Add your rule in priority order
        AssignmentRule::HIGH_SKILL,
        AssignmentRule::LOW_SKILL,
        AssignmentRule::PARTNER,
        AssignmentRule::REPEAT,      // Lowest priority
    ];

    // Optimization loop continues as before...
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

Create tests in `tests/Unit/Domain/AssignmentServiceTest.php`:

```php
public function testYourNewRuleLoss(): void
{
    $service = new AssignmentService();
    $crew = new Crew(...);
    $boat = new Boat(...);

    // Set up test conditions
    $crew->setHistory(['event1' => 'boat1', 'event2' => 'boat1']);

    // Calculate loss
    $loss = $service->crew_loss(
        $crew,
        $boat,
        AssignmentRule::YOUR_NEW_RULE,
        ['recent_events' => 2]
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
enum AssignmentRule: string
{
    // ... existing rules
    case BALANCED_EXPERIENCE = 'balanced_experience';
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
$rules = [
    AssignmentRule::ASSIST,
    AssignmentRule::WHITELIST,
    AssignmentRule::BALANCED_EXPERIENCE,  // Add after critical rules
    AssignmentRule::HIGH_SKILL,
    // ...
];
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

The `bad_swap()` method checks if a swap is valid. You may need to add validation logic:

```php
private function bad_swap(Crew $crew, Boat $fromBoat, Boat $toBoat): bool
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

The `$context` array can pass additional data needed for your calculations:

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
- **Tests:** `tests/Unit/Domain/AssignmentServiceTest.php`
- **Documentation:** `CLAUDE.md` - "Assignment Optimization Algorithm"
