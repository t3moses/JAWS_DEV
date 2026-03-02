# Add Ranking Criteria Skill

Guide for adding a new ranking dimension to the boat or crew ranking system in JAWS.

## Overview

The JAWS system uses multi-dimensional ranking for prioritizing boats and crews:

- **Boats**: `[flexibility, absence]` (2D)
- **Crews**: `[commitment, flexibility, membership, absence]` (4D)

Rankings are compared lexicographically during bubble sort. **Lower rank = higher priority.**

## Steps to Add New Ranking Criteria

### 1. Add Enum Constant

**For Boat Ranking:**
Edit `src/Domain/Enum/BoatRankDimension.php`:

```php
enum BoatRankDimension: int
{
    case FLEXIBILITY = 0;
    case ABSENCE = 1;
    case YOUR_NEW_DIMENSION = 2;  // Add new dimension
}
```

**For Crew Ranking:**
Edit `src/Domain/Enum/CrewRankDimension.php`:

```php
enum CrewRankDimension: int
{
    case COMMITMENT = 0;
    case FLEXIBILITY = 1;
    case MEMBERSHIP = 2;
    case ABSENCE = 3;
    case YOUR_NEW_DIMENSION = 4;  // Add new dimension
}
```

### 2. Update Entity Constructor

**For Boat Entity:**
Edit `src/Domain/Entity/Boat.php`:

```php
public function __construct(
    private BoatKey $key,
    // ... other properties
) {
    // Update rank array size from 2D to 3D
    $this->rank = new Rank([0, 0, 0]);  // Add 0 for new dimension
}
```

**For Crew Entity:**
Edit `src/Domain/Entity/Crew.php`:

```php
public function __construct(
    private CrewKey $key,
    // ... other properties
) {
    // Update rank array size from 4D to 5D
    $this->rank = new Rank([0, 0, 0, 0, 0]);  // Add 0 for new dimension
}
```

### 3. Implement Calculation Logic

Edit `src/Domain/Service/RankingService.php`:

**For Boat Ranking:**

```php
public function calculateBoatRank(
    Boat $boat,
    array $pastEventIds,
    ?Fleet $fleet = null
): Rank {
    // Existing dimensions...
    $flexibility = $this->calculateFlexibility($boat);
    $absence = $this->calculateAbsence($boat, $pastEventIds);

    // NEW: Calculate your new dimension
    $yourNewDimension = $this->calculateYourNewDimension($boat);

    return new Rank([
        $flexibility,
        $absence,
        $yourNewDimension  // Add to rank tensor
    ]);
}

private function calculateYourNewDimension(Boat $boat): int
{
    // Implement your calculation logic here
    // Return an integer where LOWER = HIGHER PRIORITY
    // Example: return $boat->hasSpecialFeature() ? 0 : 1;
}
```

**For Crew Ranking:**

```php
public function calculateCrewRank(
    Crew $crew,
    array $pastEventIds,
    ?EventId $nextEventId = null,
    ?Fleet $fleet = null
): Rank {
    // Existing dimensions...
    $commitment = ...;
    $flexibility = ...;
    $membership = ...;
    $absence = ...;

    // NEW: Calculate your new dimension
    $yourNewDimension = $this->calculateYourNewDimension($crew);

    return new Rank([
        $commitment,
        $flexibility,
        $membership,
        $absence,
        $yourNewDimension  // Add to rank tensor
    ]);
}

private function calculateYourNewDimension(Crew $crew): int
{
    // Implement your calculation logic here
    // Return an integer where LOWER = HIGHER PRIORITY
}
```

### 4. Update Comparison Logic (If Needed)

**Only if your ranking changes lexicographic comparison behavior:**

Edit `src/Domain/Service/SelectionService.php`:

```php
private function is_greater(Rank $a, Rank $b): bool
{
    // Existing logic handles lexicographic comparison
    // Usually no changes needed here
    // Only modify if you need special comparison logic
}
```

### 5. Write Tests

Create tests in `tests/Unit/Domain/RankingServiceTest.php`:

```php
public function testCalculateBoatRankWithNewDimension(): void
{
    $service = new RankingService();
    $boat = new Boat(...);

    // Set up conditions for your new dimension
    $boat->setYourNewProperty(true);

    $rank = $service->calculateBoatRank($boat, []);

    // Verify the new dimension value
    $this->assertEquals(0, $rank->getDimension(2));  // Index 2 for 3rd dimension
}
```

### 6. Update Documentation

Update `CLAUDE.md` section "Multi-Dimensional Ranking System":

```markdown
## Multi-Dimensional Ranking System

The system uses rank tensors for prioritization:

- **Boats**: `[flexibility, absence, your_new_dimension]` (3D)
- **Crews**: `[commitment, flexibility, membership, absence, your_new_dimension]` (5D)

**Rank Components:**
- `your_new_dimension` - Description of what this dimension represents
```

## Example: Adding "Experience" Ranking for Crews

```php
// 1. Add enum constant
enum CrewRankDimension: int
{
    case COMMITMENT = 0;
    case FLEXIBILITY = 1;
    case MEMBERSHIP = 2;
    case ABSENCE = 3;
    case EXPERIENCE = 4;  // New dimension
}

// 2. Update Crew constructor
$this->rank = new Rank([0, 0, 0, 0, 0]);  // 5D now

// 3. Implement calculation
private function calculateExperience(Crew $crew): int
{
    // More experience = lower rank (higher priority)
    $years = $crew->getYearsExperience();

    if ($years >= 10) return 0;      // Highly experienced
    if ($years >= 5) return 1;       // Moderately experienced
    if ($years >= 2) return 2;       // Some experience
    return 3;                        // Novice
}

// 4. Add to rank calculation
public function calculateCrewRank(...): Rank {
    $commitment = ...;
    $flexibility = ...;
    $membership = ...;
    $absence = ...;
    $experience = $this->calculateExperience($crew);

    return new Rank([
        $commitment,
        $flexibility,
        $membership,
        $absence,
        $experience
    ]);
}
```

## Important Considerations

### Ranking Priority

Remember: **Lower values = Higher priority**

If you want experienced crews to rank higher:
- Experienced = 0
- Novice = 3

### Lexicographic Comparison

Rankings are compared left-to-right:
1. First dimension compared first
2. If tied, second dimension compared
3. And so on...

**Order matters!** Place most important criteria first.

### Testing Impact

After adding a ranking dimension:
1. Run all tests: `./vendor/bin/phpunit`
2. Test with real data to verify prioritization works as expected
3. Verify deterministic behavior (same inputs = same outputs)

### Database Changes

If your new dimension requires new database fields:
1. Create migration to add field
2. Update repository to persist the data
3. Update DTOs if exposed via API

## Checklist

- [ ] Enum constant added to `BoatRankDimension` or `CrewRankDimension`
- [ ] Entity constructor updated with new rank dimension
- [ ] Calculation method implemented in `RankingService`
- [ ] Rank calculation updated to include new dimension
- [ ] Tests written and passing
- [ ] Documentation updated in `CLAUDE.md`
- [ ] Real-world testing completed
- [ ] Dimension order verified (most important criteria first)

## Critical Warning

⚠️ **PRESERVE BUSINESS LOGIC**

The SelectionService and AssignmentService algorithms must produce identical results to the legacy system (except for intentional improvements). When adding ranking criteria:

1. Document why the new criterion is needed
2. Test thoroughly with historical data
3. Verify deterministic behavior
4. Get stakeholder approval before deploying

## References

- **RankingService:** `src/Domain/Service/RankingService.php`
- **Rank Value Object:** `src/Domain/ValueObject/Rank.php`
- **SelectionService:** `src/Domain/Service/SelectionService.php`
- **Boat Entity:** `src/Domain/Entity/Boat.php`
- **Crew Entity:** `src/Domain/Entity/Crew.php`
