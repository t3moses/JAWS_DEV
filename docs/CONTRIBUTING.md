# Contributing to JAWS

Thank you for considering contributing to JAWS! This guide will help you understand our development process and coding standards.

## Table of Contents

- [Getting Started](#getting-started)
- [Git Workflow](#git-workflow)
- [Code Style](#code-style)
- [Testing Requirements](#testing-requirements)
- [Documentation Requirements](#documentation-requirements)
- [Pull Request Process](#pull-request-process)
- [Questions and Help](#questions-and-help)

---

## Getting Started

Before you start contributing:

1. **Set up your development environment**
   - Follow the [Setup Guide](SETUP.md) to install dependencies and configure your environment
   - Ensure all tests pass: `./vendor/bin/phpunit`
   - Start the development server: `php -S localhost:8000 -t public`

2. **Understand the architecture**
   - Read the [Developer Guide](DEVELOPER_GUIDE.md) to learn about Clean Architecture
   - Familiarize yourself with the four layers: Domain, Application, Infrastructure, Presentation
   - Review existing code to understand patterns and conventions

3. **Find something to work on**
   - Check the issue tracker for open issues
   - Look for issues labeled "good first issue" if you're new
   - Or propose a new feature by opening an issue first

---

## Git Workflow

### Branch Naming Conventions

Use descriptive branch names that indicate the type of work and what it does:

**Format:** `<type>/<short-description>`

**Types:**
- `feature/` - New features
- `fix/` - Bug fixes
- `refactor/` - Code refactoring
- `docs/` - Documentation changes
- `test/` - Adding or updating tests

**Examples:**
```bash
feature/add-crew-notes
fix/prevent-duplicate-assignments
refactor/extract-ranking-service
docs/update-api-documentation
test/add-selection-service-tests
```

### Creating a Branch

```bash
# Update your local main branch
git checkout main
git pull origin main

# Create and switch to new branch
git checkout -b feature/add-crew-notes
```

### Commit Message Format

JAWS uses [Conventional Commits](https://www.conventionalcommits.org/) specification for all commit messages.

**Format:**
```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

#### Types

- **feat**: A new feature
- **fix**: A bug fix
- **docs**: Documentation only changes
- **style**: Changes that don't affect code meaning (formatting, whitespace, etc.)
- **refactor**: Code change that neither fixes a bug nor adds a feature
- **perf**: Code change that improves performance
- **test**: Adding missing tests or correcting existing tests
- **build**: Changes affecting build system or external dependencies
- **ci**: Changes to CI configuration files and scripts
- **chore**: Other changes that don't modify src or test files

#### Rules

1. Use lowercase for type and description
2. No period at the end of the description
3. Use imperative mood ("add" not "added" or "adds")
4. Keep description under 72 characters
5. Add body if change needs explanation (use blank line after description)
6. Reference issue numbers in footer if applicable

#### Examples

**Simple commit:**
```bash
git commit -m "feat: add crew notes field to database schema"
```

**Commit with body:**
```bash
git commit -m "fix: prevent duplicate crew assignments on same boat

The assignment algorithm was not properly checking for existing
assignments before adding new ones. This change adds a validation
step to check for duplicates before making assignments.

Fixes #42"
```

**More examples:**
```bash
feat: add crew notes field to database schema
fix: prevent duplicate crew assignments on same boat
docs: update API documentation for availability endpoint
test: add integration tests for AssignmentService
refactor: extract rank calculation into separate service
perf: optimize database queries for flotilla generation
style: format code according to PSR-12 standards
ci: add automated testing workflow
chore: update dependencies to latest versions
```

### Making Commits

```bash
# Stage your changes
git add .

# Or stage specific files
git add src/Domain/Entity/Crew.php

# Commit with conventional commit message
git commit -m "feat: add notes field to crew entity"

# Push to your branch
git push origin feature/add-crew-notes
```

---

## Code Style

JAWS follows [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.

### PSR-12 Key Points

**Indentation:**
```php
// Use 4 spaces (no tabs)
class Boat
{
    public function calculateRank(): array
    {
        return [0, 1];
    }
}
```

**Strict Types:**
```php
// Always declare strict types at the top
<?php

declare(strict_types=1);

namespace App\Domain\Entity;
```

**Type Hints:**
```php
// Always use type hints for parameters and return types
public function findByKey(BoatKey $key): ?Boat
{
    // ...
}

// Use union types when appropriate (PHP 8.0+)
public function save(Boat|Crew $entity): void
{
    // ...
}
```

**Readonly Properties:**
```php
// Use readonly for immutable properties (PHP 8.1+)
class Boat
{
    public function __construct(
        private readonly BoatKey $key,
        private readonly string $displayName
    ) {}
}
```

**Naming Conventions:**
```php
// Classes: PascalCase
class BoatRepository {}
class SelectionService {}

// Methods: camelCase
public function findByKey() {}
public function calculateRank() {}

// Properties: camelCase
private string $displayName;
private BoatKey $boatKey;

// Constants: SCREAMING_SNAKE_CASE
const ASSIST = 'assist';
const WHITELIST = 'whitelist';
```

**Visibility:**
```php
// Always declare visibility
public function publicMethod() {}
protected function protectedMethod() {}
private function privateMethod() {}

private string $property;
```

**Braces:**
```php
// Opening brace on same line for methods
public function method()
{
    // ...
}

// Opening brace on same line for control structures
if ($condition) {
    // ...
}

foreach ($items as $item) {
    // ...
}
```

**One Class Per File:**
```php
// File: src/Domain/Entity/Boat.php
class Boat
{
    // ...
}
// Only Boat class in this file
```

### Code Organization

**Layer Boundaries:**
- Domain layer has NO external dependencies
- Application layer depends on Domain only
- Infrastructure depends on Application + Domain
- Presentation depends on Application only

**Example - Good:**
```php
// Domain Service (no dependencies)
class SelectionService
{
    public function shuffle(array $entities, EventId $eventId): array
    {
        // Pure business logic
    }
}
```

**Example - Bad:**
```php
// Domain Service (DON'T DO THIS)
class SelectionService
{
    public function shuffle(array $entities, EventId $eventId): array
    {
        // ❌ NEVER access database from Domain layer
        $pdo->query("SELECT ...");
    }
}
```

---

## Testing Requirements

All code contributions must include tests.

### What to Test

**Domain Layer:**
- Unit tests for all entities
- Unit tests for all domain services
- Unit tests for all value objects
- Test edge cases and boundary conditions

**Infrastructure Layer:**
- Integration tests for repositories
- Test database interactions with in-memory SQLite
- Test external service adapters

**Presentation Layer:**
- API tests for all endpoints
- Test authentication and authorization
- Test error handling

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Integration

# Run specific test file
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Writing Tests

**Use Arrange-Act-Assert Pattern:**

```php
public function testDeterministicShuffle(): void
{
    // Arrange - set up test data
    $eventId = new EventId('Fri May 29');
    $boats = $this->createTestBoats(10);

    // Act - execute the code under test
    $result1 = $this->selectionService->shuffle($boats, $eventId);
    $result2 = $this->selectionService->shuffle($boats, $eventId);

    // Assert - verify the result
    $this->assertEquals($result1, $result2);
}
```

**Use Descriptive Test Names:**

```php
// Good
testShuffleProducesSameOrderWithSameSeed()
testCapacityMatchingCase1TooFewCrews()

// Bad
testShuffle()
testCase1()
```

**Test One Thing Per Test:**

```php
// Good - each test verifies one behavior
testShuffleIsDeterministic()
testShuffleUsesEventIdAsSeed()

// Bad - testing multiple behaviors
testShuffleEverything()
```

**Test Edge Cases:**

```php
testEmptyArrayInput()
testNullValue()
testMaximumCapacity()
testMinimumCapacity()
```

### Test Coverage Requirements

- **Minimum coverage**: 70% overall
- **Domain layer**: 90% coverage required
- **Critical algorithms**: 100% coverage required (SelectionService, AssignmentService)

Check coverage:
```bash
./vendor/bin/phpunit --coverage-text
```

---

## Documentation Requirements

### Code Documentation

**Inline Comments:**
- Use inline comments for complex logic
- Don't comment obvious code
- Explain "why" not "what"

```php
// Good
// Use CRC32 hash of event ID as seed to ensure deterministic shuffling
srand(crc32((string) $eventId));

// Bad
// Set the seed
srand(crc32((string) $eventId));
```

**PHPDoc Blocks:**
- Required for all public methods
- Optional for private methods (use if complex)
- Include parameter types and return types

```php
/**
 * Calculate multi-dimensional rank for a boat
 *
 * @param Boat $boat The boat to rank
 * @param array $history Historical participation data
 * @return array Rank tensor [flexibility, absence]
 */
public function calculateBoatRank(Boat $boat, array $history): array
{
    // ...
}
```

### Documentation Files

Update documentation when making significant changes:

**CLAUDE.md:**
- Update for major architectural changes
- Add new critical files or patterns
- Update layer responsibilities

**API.md:**
- Document new endpoints
- Update request/response examples
- Add error codes

**DEVELOPER_GUIDE.md:**
- Document new development patterns
- Add examples for common tasks
- Update troubleshooting section

**SETUP.md:**
- Update for new dependencies
- Add new configuration options
- Update troubleshooting steps

---

## Pull Request Process

### Before Creating a Pull Request

- [ ] All tests pass locally: `./vendor/bin/phpunit`
- [ ] Code follows PSR-12 style guide
- [ ] New code has tests with adequate coverage
- [ ] Documentation updated (if applicable)
- [ ] Commit messages follow Conventional Commits format
- [ ] Branch is up to date with main: `git pull origin main`

### Creating a Pull Request

1. **Push your branch:**
   ```bash
   git push origin feature/add-crew-notes
   ```

2. **Open Pull Request on GitHub:**
   - Navigate to the repository
   - Click "New Pull Request"
   - Select your branch
   - Fill out the PR template

3. **PR Title:**
   - Use the same format as commit messages
   - Example: "feat: add notes field to crew entity"

4. **PR Description:**
   - Describe what the PR does
   - Reference related issues (e.g., "Fixes #42")
   - List any breaking changes
   - Add screenshots for UI changes

**Example PR Description:**

```markdown
## Description
Adds a `notes` field to the Crew entity to allow storing additional information about crew members.

## Changes
- Added `notes` column to `crews` table via Phinx migration
- Updated `Crew` entity with `notes` property
- Updated `CrewRepository` to handle notes field
- Updated `CrewResponse` DTO to include notes
- Added tests for new functionality

## Related Issues
Closes #42

## Testing
- Unit tests added for Crew entity
- Integration tests added for CrewRepository
- All existing tests still pass
```

### Review Process

1. **Automated Checks:**
   - Tests must pass (CI/CD if configured)
   - Code must follow style guide

2. **Code Review:**
   - At least one approval required
   - Address all reviewer comments
   - Make requested changes in new commits

3. **After Approval:**
   - Squash commits if requested
   - Merge to main
   - Delete feature branch

### Responding to Review Comments

**Be respectful and professional:**
- Thank reviewers for their feedback
- Ask for clarification if needed
- Explain your reasoning if you disagree

**Make requested changes:**
```bash
# Make changes based on feedback
git add .
git commit -m "refactor: simplify rank calculation logic"
git push origin feature/add-crew-notes
```

**Mark conversations as resolved:**
- After addressing a comment, mark it as resolved
- If you didn't make the change, explain why

---

## Questions and Help

### Where to Ask Questions

**GitHub Issues:**
- For bugs, feature requests, or general questions
- Search existing issues first to avoid duplicates

**Code Questions:**
- Add comments in your PR for specific questions
- Tag relevant team members for review

**Documentation:**
- Check the `/docs` folder for detailed guides
- Review `CLAUDE.md` for technical specifications
- Read phase completion documents in `/docs/PHASE_*.md`

### Getting Help

**For Setup Issues:**
- See [Setup Guide](SETUP.md) troubleshooting section
- Check [Common Setup Issues](SETUP.md#common-setup-issues)

**For Development Questions:**
- See [Developer Guide](DEVELOPER_GUIDE.md)
- Review [Common Patterns](DEVELOPER_GUIDE.md#common-patterns)

**For Testing Questions:**
- See [Testing Guide](DEVELOPER_GUIDE.md#testing-guide)
- Review existing tests for examples

**For Deployment Questions:**
- See [Deployment Guide](DEPLOYMENT.md)
- Review [Troubleshooting](DEPLOYMENT.md#troubleshooting) section

---

## Thank You!

Thank you for contributing to JAWS! Your contributions help improve the Social Day Cruising program at Nepean Sailing Club.

---

📖 **Related Documentation:**

- [Setup Guide](SETUP.md) - Get your development environment ready
- [Developer Guide](DEVELOPER_GUIDE.md) - Learn the architecture and patterns
- [API Reference](API.md) - Understand the API endpoints
- [Deployment Guide](DEPLOYMENT.md) - Deploy to production
- [CLAUDE.md](../CLAUDE.md) - Complete technical specifications
