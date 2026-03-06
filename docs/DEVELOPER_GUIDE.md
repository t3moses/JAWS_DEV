# JAWS Developer Guide

This guide provides everything you need to know to develop features for JAWS, from understanding the architecture to writing tests and following best practices.

## Table of Contents

- [Clean Architecture Overview](#clean-architecture-overview)
- [Project Structure](#project-structure)
- [Development Workflow](#development-workflow)
- [Testing Guide](#testing-guide)
- [Database Schema Changes](#database-schema-changes)
- [Adding New Features](#adding-new-features)
- [Common Patterns](#common-patterns)
- [Critical Algorithms](#critical-algorithms)
- [Code Style](#code-style)
- [Troubleshooting](#troubleshooting)

---

## Clean Architecture Overview

JAWS uses Clean Architecture (also called Hexagonal Architecture or Ports and Adapters) to separate business logic from external concerns like databases, frameworks, and user interfaces.

![alt text](clean-architecture-diagram.png "Clean Architecture diagram")

### What is Clean Architecture?

**Core Principles:**

1. **Dependency Rule**: Dependencies point inward. Outer layers can depend on inner layers, but never the reverse.
2. **Independence**: Business logic doesn't know about databases, frameworks, or UIs
3. **Testability**: Inner layers can be tested without external dependencies
4. **Flexibility**: Swap implementations (e.g., SQLite → PostgreSQL) without changing business logic

### The Four Layers

```
┌─────────────────────────────────────────┐
│   Presentation (HTTP/API)               │  Outer Layer
│   - Controllers                         │  (Delivery Mechanism)
│   - Middleware                          │
│   - Request/Response Formatting         │
└─────────────────────────────────────────┘
              ↓ depends on
┌─────────────────────────────────────────┐
│   Infrastructure (External Services)    │  Adapter Layer
│   - Database Repositories               │  (Connects to Outside World)
│   - Email Service                       │
│   - Calendar Service                    │
└─────────────────────────────────────────┘
              ↓ depends on
┌─────────────────────────────────────────┐
│   Application (Use Cases)               │  Use Case Layer
│   - UpdateBoatAvailabilityUseCase       │  (Application-Specific Rules)
│   - ProcessSeasonUpdateUseCase          │
│   - Repository Interfaces (Ports)       │
└─────────────────────────────────────────┘
              ↓ depends on
┌─────────────────────────────────────────┐
│   Domain (Business Logic)               │  Core Layer
│   - Entities (Boat, Crew)               │  (Business Rules)
│   - Value Objects (Rank, BoatKey)       │  (Framework-Independent)
│   - Domain Services (Selection)         │
│   - Business Rules                      │
└─────────────────────────────────────────┘
```

**Key Insight:** Dependency always points inward. The Domain layer has ZERO external dependencies—it's pure PHP with no knowledge of databases, HTTP, or frameworks.

### Data Flow Example

Here's how an availability update flows through the layers:

```
1. HTTP Request
   PATCH /api/users/me/availability
   { "availabilities": { "Fri May 29": 2 } }
   ↓

2. Presentation Layer (Controller)
   - Extract JSON from HTTP body
   - Extract auth data from JWT token
   - Create UpdateAvailabilityRequest DTO
   - Auto-detect user role (boat/crew/flex)
   - Call appropriate use case(s)
   ↓

3. Application Layer (Use Case)
   - Validate request data
   - Find entity via Repository (Interface)
   - Update availability via Repository
   ↓

4. Infrastructure Layer (Repository)
   - Execute SQL UPDATE on database
   - Return success
   ↓

5. Application Layer (Use Case)
   - Return Response DTO to Controller
   ↓

6. Presentation Layer (Controller)
   - Create JsonResponse
   - Set HTTP status code 200
   - Return JSON with data
   ↓

7. HTTP Response
   { "success": true, "data": { ... } }
```

**Notice:**
- Domain layer has no knowledge of HTTP or database
- Use Case has no knowledge of HTTP or SQL
- Only Infrastructure knows about database
- Only Presentation knows about HTTP

---

## Project Structure

### Directory Tree

```
JAWS/
├── config/                 # Application configuration
│   ├── config.php         # Environment variables and settings
│   ├── container.php      # Dependency injection container
│   └── routes.php         # API route definitions
│
├── database/              # Database files
│   ├── jaws.db           # SQLite database (gitignored)
│   ├── migrations/       # Phinx migration files
│   └── seeds/            # Phinx seed files
│
├── docs/                  # Documentation
│   ├── SETUP.md          # Setup instructions
│   ├── DEVELOPER_GUIDE.md # This file
│   ├── API.md            # API documentation
│   ├── DEPLOYMENT.md     # Deployment guide
│   └── CONTRIBUTING.md   # Contribution guidelines
│
├── public/                # Web server document root
│   ├── index.php         # Application entry point
│   ├── .htaccess         # Apache rewrite rules
│   └── app/              # Frontend application
│
├── src/                   # Application source code
│   ├── Domain/           # Layer 1: Core business logic
│   │   ├── Entity/       # Business objects (Boat, Crew)
│   │   ├── ValueObject/  # Immutable values (BoatKey, Rank)
│   │   ├── Enum/         # Constants (SkillLevel, AvailabilityStatus)
│   │   ├── Service/      # Business algorithms (Selection, Assignment)
│   │   └── Collection/   # In-memory collections (Fleet, Squad)
│   │
│   ├── Application/      # Layer 2: Use cases
│   │   ├── UseCase/      # Application workflows
│   │   ├── Port/         # Interfaces for outer layers
│   │   ├── DTO/          # Data transfer objects
│   │   └── Exception/    # Application exceptions
│   │
│   ├── Infrastructure/   # Layer 3: External adapters
│   │   ├── Persistence/  # Database implementations
│   │   │   └── SQLite/   # SQLite-specific repositories
│   │   └── Service/      # External service implementations
│   │
│   └── Presentation/     # Layer 4: HTTP/API
│       ├── Controller/   # HTTP handlers
│       ├── Middleware/   # Request/response processing
│       ├── Router.php    # URL pattern matching
│       └── Response/     # Response formatting
│
├── tests/                 # Automated tests
│   ├── Unit/             # Unit tests (Domain layer)
│   │   └── Domain/       # Test domain logic without dependencies
│   └── Integration/      # Integration tests (Infrastructure + API)
│       ├── Infrastructure/ # Test repositories with database
│       └── Api/          # PHPUnit API endpoint tests
│           ├── EventApiTest.php
│           ├── AuthApiTest.php
│           ├── UserProfileApiTest.php
│           ├── AvailabilityApiTest.php
│           ├── AssignmentApiTest.php
│           ├── AdminApiTest.php
│           └── ApiTestTrait.php  # Shared test utilities
│
├── tests/                 # Legacy test directory
│   └── JAWS_API.postman_collection.json
│
├── .env                   # Environment configuration (gitignored)
├── .env.example          # Example environment file
├── composer.json         # PHP dependencies
├── phinx.php             # Phinx configuration
├── phpunit.xml           # PHPUnit configuration
├── CLAUDE.md             # AI assistant project guide
└── README.md             # Project overview
```

### Layer Responsibilities

**Domain Layer** (`src/Domain/`)
- Core business logic and rules
- **NO** external dependencies (pure PHP)
- Contains: Entities, Value Objects, Enums, Domain Services, Collections

**Application Layer** (`src/Application/`)
- Use cases and application orchestration
- Depends on: Domain layer only
- Contains: Use Cases, Ports (interfaces), DTOs, Exceptions

**Infrastructure Layer** (`src/Infrastructure/`)
- External service adapters
- Depends on: Application + Domain layers
- Contains: Repositories, Email Service, Calendar Service, Time Service

**Presentation Layer** (`src/Presentation/`)
- HTTP/REST API
- Depends on: Application layer
- Contains: Controllers, Middleware, Router, Response formatters

---

## Development Workflow

### Daily Development

1. **Pull Latest Changes**
   ```bash
   git pull origin main
   ```

2. **Create Feature Branch**
   ```bash
   git checkout -b feature/add-crew-notes
   ```

3. **Make Changes** (following Clean Architecture layers)
   - Start with Domain (entities, value objects)
   - Add Application layer (use cases, DTOs)
   - Implement Infrastructure (repositories)
   - Add Presentation (controllers)

4. **Write Tests**
   ```bash
   ./vendor/bin/phpunit
   ```

5. **Run Static Analysis**
   ```bash
   vendor/bin/phpstan analyse
   ```

6. **Test API Manually**
   ```bash
   php -S localhost:8000 -t public
   # Test with Postman or curl
   ```

7. **Commit Changes**
   ```bash
   git add .
   git commit -m "feat: add notes field to crew"
   ```

8. **Push and Create PR**
   ```bash
   git push origin feature/add-crew-notes
   # Create Pull Request on GitHub
   ```

### Common Tasks

#### Add a New API Endpoint

1. **Define Use Case** (`src/Application/UseCase/`)
   - Create new use case class
   - Inject required repositories via constructor
   - Implement `execute()` method

2. **Create Request/Response DTOs** (`src/Application/DTO/`)
   - Request DTO for input validation
   - Response DTO for serialization

3. **Implement Controller Method** (`src/Presentation/Controller/`)
   - Extract data from HTTP request
   - Call use case
   - Return JsonResponse

4. **Add Route** (`config/routes.php`)
   - Map URL pattern to controller method

5. **Wire Dependencies** (`config/container.php`)
   - Register use case in container
   - Inject dependencies

6. **Write Tests**
   - Unit test for use case
   - Integration test for repository
   - API test for endpoint

7. **Update Postman Collection** (`tests/JAWS_API.postman_collection.json`)

#### Add a New Domain Entity

1. **Create Entity Class** (`src/Domain/Entity/`)
   - Define properties and methods
   - Use value objects for identifiers
   - No database knowledge

2. **Create Repository Interface** (`src/Application/Port/Repository/`)
   - Define methods for persistence
   - Use domain types only

3. **Implement Repository** (`src/Infrastructure/Persistence/SQLite/`)
   - Implement interface methods
   - Map between database and domain

4. **Create Database Migration** (`database/migrations/`)
   - Use Phinx to create migration
   - Define table schema

5. **Wire in Dependency Injection** (`config/container.php`)
   - Register repository

6. **Write Tests**
   - Unit test entity logic
   - Integration test repository

#### Modify Business Logic

1. **Update Domain Service** (`src/Domain/Service/`)
   - Modify algorithm or rules
   - Keep it database-agnostic

2. **Write/Update Unit Tests**
   - Test new behavior
   - Verify edge cases

3. **Verify Integration Tests Still Pass**
   - Run full test suite

4. **Update Documentation**
   - Update CLAUDE.md if architecture changes
   - Update this guide if patterns change

---

## Testing Guide

JAWS uses PHPUnit for automated testing. Tests are organized into Unit and Integration tests.

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run only unit tests
./vendor/bin/phpunit tests/Unit

# Run only integration tests
./vendor/bin/phpunit tests/Integration

# Run specific test file
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php

# Run with coverage report
./vendor/bin/phpunit --coverage-html coverage
```

### Writing Unit Tests

Unit tests test individual classes in isolation with no external dependencies.

**Location:** `tests/Unit/Domain/`

**Example:** Testing the SelectionService

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use App\Domain\Service\SelectionService;
use App\Domain\Entity\Boat;
use App\Domain\ValueObject\EventId;

class SelectionServiceTest extends TestCase
{
    private SelectionService $selectionService;

    protected function setUp(): void
    {
        $this->selectionService = new SelectionService();
    }

    public function testDeterministicShuffle(): void
    {
        // Arrange
        $eventId = new EventId('Fri May 29');
        $boats = $this->createTestBoats(10);

        // Act
        $result1 = $this->selectionService->shuffle($boats, $eventId);
        $result2 = $this->selectionService->shuffle($boats, $eventId);

        // Assert - same seed should produce same order
        $this->assertEquals($result1, $result2);
    }

    public function testCapacityMatchingCase1TooFewCrews(): void
    {
        // Arrange
        $boats = $this->createTestBoats(5); // 5 boats needing 2 crew each = 10 spots
        $crews = $this->createTestCrews(7); // Only 7 crew available

        // Act
        $result = $this->selectionService->cut($boats, $crews);

        // Assert
        $this->assertCount(3, $result['crewed_boats'], 'Should crew 3 boats (6 crew)');
        $this->assertCount(2, $result['waitlist_boats'], 'Should have 2 boats on waitlist');
        $this->assertCount(1, $result['waitlist_crews'], 'Should have 1 crew on waitlist');
    }

    private function createTestBoats(int $count): array
    {
        $boats = [];
        for ($i = 0; $i < $count; $i++) {
            $boats[] = new Boat(/* constructor parameters */);
        }
        return $boats;
    }
}
```

**Run this test:**
```bash
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

### Writing Integration Tests

Integration tests test how components work together, including database interactions.

**Location:** `tests/Integration/Infrastructure/`

**Example:** Testing the BoatRepository

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure;

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Persistence\SQLite\Connection;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Domain\Entity\Boat;
use App\Domain\ValueObject\BoatKey;

class BoatRepositoryTest extends TestCase
{
    private BoatRepository $repository;
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Use in-memory SQLite for tests
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Apply schema
        $schema = file_get_contents(__DIR__ . '/../../../database/migrations/001_initial_schema.sql');
        $this->pdo->exec($schema);

        // Inject test database connection
        Connection::setTestConnection($this->pdo);

        $this->repository = new BoatRepository();
    }

    protected function tearDown(): void
    {
        Connection::resetTestConnection();
    }

    public function testSaveAndFindBoat(): void
    {
        // Arrange
        $boat = new Boat(
            new BoatKey('sailaway'),
            'Sail Away',
            'John',
            'Doe',
            'john@example.com',
            '555-1234',
            1,
            3,
            false,
            false
        );

        // Act
        $this->repository->save($boat);
        $found = $this->repository->findByKey(new BoatKey('sailaway'));

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals('Sail Away', $found->getDisplayName());
        $this->assertEquals('john@example.com', $found->getOwnerEmail());
    }
}
```

**Run this test:**
```bash
./vendor/bin/phpunit tests/Integration/Infrastructure/BoatRepositoryTest.php
```

### Test Best Practices

1. **Arrange-Act-Assert Pattern**
   ```php
   // Arrange - set up test data
   $input = createTestData();

   // Act - execute the code under test
   $result = $service->doSomething($input);

   // Assert - verify the result
   $this->assertEquals($expected, $result);
   ```

2. **One Assertion Per Test**
   - Good: `testShuffleProducesSameOrderWithSameSeed()`
   - Bad: `testEverythingAboutShuffle()`

3. **Use Descriptive Names**
   ```php
   testCapacityMatchingCase1TooFewCrews()  // Good
   testCase1()                              // Bad
   ```

4. **Test Edge Cases**
   - Empty arrays
   - Null values
   - Boundary conditions (min/max values)
   - Invalid inputs

5. **Mock External Dependencies**
   ```php
   $emailService = $this->createMock(EmailServiceInterface::class);
   $emailService->expects($this->once())
       ->method('send')
       ->with($this->equalTo('test@example.com'));
   ```

6. **Use In-Memory Database for Integration Tests**
   ```php
   $pdo = new \PDO('sqlite::memory:');
   ```

7. **Clean Up After Tests**
   ```php
   protected function tearDown(): void
   {
       Connection::resetTestConnection();
   }
   ```

### Domain Layer Test Coverage

The Domain layer has comprehensive unit test coverage:

- **Value Objects**: BoatKey, CrewKey, EventId, Rank
- **Enums**: AvailabilityStatus, SkillLevel, BoatRankDimension, CrewRankDimension
- **Entities**: Boat, Crew
- **Collections**: Fleet, Squad
- **Services**: RankingService, FlexService

All tests are pure unit tests with **no external dependencies**.

---

## Database Schema Changes

JAWS uses [Phinx](https://phinx.org/) for database migrations. All schema changes must be made through Phinx.

### Creating a New Migration

```bash
# Create migration with descriptive name
vendor/bin/phinx create AddCrewNotes

# This creates: database/migrations/YYYYMMDDHHMMSS_add_crew_notes.php
```

### Writing a Migration

```php
<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCrewNotes extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('crews');
        $table->addColumn('notes', 'text', ['null' => true])
              ->update();
    }
}
```

### Applying Migrations

```bash
# Apply all pending migrations
vendor/bin/phinx migrate

# Rollback last migration
vendor/bin/phinx rollback

# Check migration status
vendor/bin/phinx status
```

### Updating Domain Entities

After creating a migration, update the corresponding entity:

**File:** `src/Domain/Entity/Crew.php`

```php
class Crew
{
    private ?string $notes;

    public function __construct(
        private CrewKey $key,
        // ... other parameters
        ?string $notes = null
    ) {
        $this->notes = $notes;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
```

### Updating Repository Implementation

**File:** `src/Infrastructure/Persistence/SQLite/CrewRepository.php`

Update `save()` and `mapRowToCrew()` methods to handle the new field.

### Complete Workflow

1. Create migration: `vendor/bin/phinx create MyMigration`
2. Write migration code in generated PHP file
3. Update Domain entity class
4. Update Infrastructure repository
5. Update Application DTOs (if exposed via API)
6. Apply migration: `vendor/bin/phinx migrate`
7. Write tests for new functionality
8. Document changes

📖 **See also:** [Database README](../database/README.md) - Detailed migration documentation

---

## Adding New Features

### Adding a New API Endpoint

**Example:** Add endpoint to get crew whitelist

1. **Create Use Case** (`src/Application/UseCase/Crew/GetWhitelistUseCase.php`)
   ```php
   class GetWhitelistUseCase
   {
       public function __construct(
           private CrewRepositoryInterface $crewRepository
       ) {}

       public function execute(CrewKey $crewKey): WhitelistResponse
       {
           $whitelist = $this->crewRepository->getWhitelist($crewKey);
           return WhitelistResponse::fromArray($whitelist);
       }
   }
   ```

2. **Create Response DTO** (`src/Application/DTO/Response/WhitelistResponse.php`)
   ```php
   class WhitelistResponse
   {
       public function __construct(
           public readonly array $boatKeys
       ) {}

       public static function fromArray(array $boatKeys): self
       {
           return new self($boatKeys);
       }

       public function toArray(): array
       {
           return ['boat_keys' => $this->boatKeys];
       }
   }
   ```

3. **Add Controller Method** (`src/Presentation/Controller/CrewController.php`)
   ```php
   public function getWhitelist(array $params, array $auth): JsonResponse
   {
       $crewKey = new CrewKey($auth['crew_key']);
       $response = $this->getWhitelistUseCase->execute($crewKey);
       return JsonResponse::success($response->toArray());
   }
   ```

4. **Add Route** (`config/routes.php`)
   ```php
   'GET /api/crews/me/whitelist' => [CrewController::class, 'getWhitelist', true],
   ```

5. **Wire in Container** (`config/container.php`)
   ```php
   $container->set(GetWhitelistUseCase::class, fn() => new GetWhitelistUseCase(
       $container->get(CrewRepositoryInterface::class)
   ));
   ```

6. **Write Tests**

### Adding a New Domain Entity

**Example:** Add Notes entity for crew notes

1. **Create Entity** (`src/Domain/Entity/Note.php`)
   ```php
   class Note
   {
       public function __construct(
           private NoteId $id,
           private CrewKey $crewKey,
           private string $content,
           private \DateTimeImmutable $createdAt
       ) {}

       public function getId(): NoteId { return $this->id; }
       public function getCrewKey(): CrewKey { return $this->crewKey; }
       public function getContent(): string { return $this->content; }
       public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
   }
   ```

2. **Create Repository Interface** (`src/Application/Port/Repository/NoteRepositoryInterface.php`)
   ```php
   interface NoteRepositoryInterface
   {
       public function save(Note $note): void;
       public function findByCrewKey(CrewKey $crewKey): array;
       public function delete(NoteId $id): void;
   }
   ```

3. **Implement Repository** (`src/Infrastructure/Persistence/SQLite/NoteRepository.php`)
   ```php
   class NoteRepository implements NoteRepositoryInterface
   {
       // Implement interface methods with SQL queries
   }
   ```

4. **Create Migration** (`database/migrations/YYYYMMDDHHMMSS_create_notes_table.php`)
   ```php
   public function change(): void
   {
       $table = $this->table('notes');
       $table->addColumn('crew_key', 'string', ['limit' => 50])
             ->addColumn('content', 'text')
             ->addColumn('created_at', 'datetime')
             ->addIndex('crew_key')
             ->create();
   }
   ```

5. **Wire Dependencies** (`config/container.php`)
   ```php
   $container->set(NoteRepositoryInterface::class, fn() => new NoteRepository());
   ```

6. **Write Tests**

---

## Common Patterns

### Dependency Injection

All dependencies are wired in `config/container.php`:

```php
$container = new Container();

// Repositories
$container->set(BoatRepositoryInterface::class, fn() => new BoatRepository());

// Services
$container->set(TimeServiceInterface::class, fn() => new SystemTimeService($config));

// Use Cases
$container->set(UpdateBoatAvailabilityUseCase::class, fn() => new UpdateBoatAvailabilityUseCase(
    $container->get(BoatRepositoryInterface::class)
));
```

### Loading and Saving Entities

```php
// Inject repository via constructor
class UpdateBoatAvailabilityUseCase {
    public function __construct(
        private BoatRepositoryInterface $boatRepository
    ) {}

    public function execute(string $ownerFirstName, string $ownerLastName, UpdateAvailabilityRequest $request): BoatResponse
    {
        // Find existing boat by owner name
        $boat = $this->boatRepository->findByOwnerName($ownerFirstName, $ownerLastName);

        // Update boat availabilities
        foreach ($request->availabilities as $eventId => $berths) {
            $this->boatRepository->setAvailability($boat->getKey(), new EventId($eventId), $berths);
        }

        return BoatResponse::fromEntity($boat);
    }
}
```

### Working with Repositories

```php
// Find by key
$boat = $boatRepository->findByKey(new BoatKey('sailaway'));
$crew = $crewRepository->findByName('John', 'Doe');

// Find by availability
$availableBoats = $boatRepository->findAvailableForEvent(new EventId('Fri May 29'));
$availableCrews = $crewRepository->findAvailableForEvent(new EventId('Fri May 29'));
```

### Error Handling

Exceptions are automatically mapped to HTTP status codes by `ErrorHandlerMiddleware`:

- `BoatNotFoundException` → 404
- `CrewNotFoundException` → 404
- `EventNotFoundException` → 404
- `ValidationException` → 400
- `BlackoutWindowException` → 403
- Generic exceptions → 500

**Example:**
```php
public function execute(UpdateAvailabilityRequest $request): void {
    if (empty($request->availabilities)) {
        throw new ValidationException('Availabilities are required');
    }
    // ...
}
```

---

## Critical Algorithms

JAWS has three critical algorithms that have been preserved from the legacy system. These algorithms are the heart of the crew assignment system.

### SelectionService

**Location:** `src/Domain/Service/SelectionService.php`

**Purpose:** Rank and select boats/crews based on multi-dimensional criteria

**Key Features:**
- Multi-dimensional ranking (boats: flexibility, absence; crews: commitment, flexibility, membership, absence)
- Deterministic shuffling using `crc32($eventId)` as seed
- Lexicographic rank comparison
- Capacity matching (3 cases: too few crews, too many crews, perfect fit)

**Critical Method:** `cut()` - Performs capacity matching

### AssignmentService

**Location:** `src/Domain/Service/AssignmentService.php`

**Purpose:** Optimize crew-to-boat assignments for the next event

**Algorithm:**
- Calculate loss and grad for each crew on each rule
- Iterate through 6 rules (ASSIST, WHITELIST, HIGH_SKILL, LOW_SKILL, PARTNER, REPEAT)
- For each rule, find highest-loss crew and best-grad swap candidate
- Perform swaps to minimize rule violations
- Lock crew after swapping to prevent thrashing

**Critical Methods:**
- `crew_loss()` - Calculate violation severity
- `crew_grad()` - Calculate mitigation capacity
- `best_swap()` - Greedy swap selection

### RankingService

**Location:** `src/Domain/Service/RankingService.php`

**Purpose:** Calculate multi-dimensional ranks for boats and crews

**Rank Components:**
- **Boats**: `[flexibility, absence]` (2D)
- **Crews**: `[commitment, flexibility, membership, absence]` (4D)

**Key Methods:**
- `calculateBoatRank()` - Calculate boat rank tensor
- `calculateCrewRank()` - Calculate crew rank tensor

⚠️ **Important:** These algorithms must produce identical results to the legacy system. Do not modify them without thorough testing and user approval.

---

## Code Style

### PSR-12 Standards

JAWS follows [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards:

**Key Rules:**
- Use 4 spaces for indentation (no tabs)
- Opening braces on same line for methods/functions
- One blank line after namespace declaration
- One class per file
- Visibility must be declared on all properties and methods

### Strict Types

Always declare strict types at the top of every PHP file:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Entity;
```

### Type Hints

Always use type hints for parameters and return types:

```php
// Good
public function findByKey(BoatKey $key): ?Boat
{
    // ...
}

// Bad
public function findByKey($key)
{
    // ...
}
```

### Readonly Properties

Use `readonly` for immutable properties (PHP 8.1+):

```php
class Boat
{
    public function __construct(
        private readonly BoatKey $key,
        private readonly string $displayName
    ) {}
}
```

### Naming Conventions

- **Classes**: PascalCase (`BoatRepository`, `SelectionService`)
- **Methods**: camelCase (`findByKey`, `calculateRank`)
- **Properties**: camelCase (`$boatKey`, `$displayName`)
- **Constants**: SCREAMING_SNAKE_CASE (`ASSIST`, `WHITELIST`)
- **Files**: Match class name (`BoatRepository.php`)

### Static Analysis

JAWS uses [PHPStan](https://phpstan.org/) at **level 5** for static analysis. Run it before committing:

```bash
vendor/bin/phpstan analyse
```

Configuration is in `phpstan.neon`. Known pre-existing warnings are suppressed via `phpstan-baseline.neon`—do not add new baseline entries for code you write. PHPStan also runs automatically in CI alongside unit tests.

---

## Troubleshooting

### Common Development Issues

**Issue: "Class not found" error**

**Solution:** Regenerate autoloader
```bash
composer dump-autoload
```

**Issue: "Database locked" error**

**Solution:** Close any open database connections
- Close SQLite Browser
- Stop development server and restart

**Issue: Tests failing after database changes**

**Solution:** Update test schema
- Ensure test migrations are applied
- Check `setUp()` method applies latest schema

**Issue: JWT token expired**

**Solution:** Get a new token
```bash
curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"your@email.com","password":"your_password"}'
```

**Issue: Port already in use**

**Solution:** Use a different port or kill the process
```bash
# Use different port
php -S localhost:3000 -t public

# Or kill process on port 8000
netstat -ano | findstr :8000  # Windows
taskkill /PID <process_id> /F

lsof -ti:8000 | xargs kill     # Mac/Linux
```

---

## Next Steps

Now that you understand the JAWS architecture and development workflow:

✅ Architecture learned!
➡️ **Next:** Read [API Reference](API.md) - Learn about available endpoints

✅ Tests written!
➡️ **Next:** Read [Contributing Guide](CONTRIBUTING.md) - Submit your changes

✅ Feature complete!
➡️ **Next:** Read [Deployment Guide](DEPLOYMENT.md) - Deploy to production

---

📖 **Additional Resources:**

- [API Reference](API.md) - Complete endpoint documentation
- [Database Management](../database/README.md) - Migrations and queries
- [Contributing Guide](CONTRIBUTING.md) - Code style and Git workflow
- [CLAUDE.md](../CLAUDE.md) - Complete technical specifications
