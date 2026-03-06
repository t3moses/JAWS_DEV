# Test Runner Skill

Runs the appropriate test suite(s) for the JAWS project using PHPUnit.

## Usage

This skill helps you run tests with the correct commands and setup.

## Test Types

### 1. All Tests
Runs the complete test suite (unit + integration + API tests):
```bash
./vendor/bin/phpunit
```

### 2. Unit Tests Only
Fast tests with no database dependencies:
```bash
./vendor/bin/phpunit tests/Unit
```

### 3. Integration Tests Only
Tests that use the database (Infrastructure layer):
```bash
./vendor/bin/phpunit tests/Integration
```

### 4. API Tests
Full end-to-end API tests (requires dev server):

**Mac/Linux:**
```bash
# Start dev server in background
php -S localhost:8000 -t public &

# Run API tests
./vendor/bin/phpunit --testsuite=API

# Or run specific API test file
./vendor/bin/phpunit tests/Integration/Api/EventApiTest.php
```

**Windows PowerShell:**
```powershell
$p = Start-Process php -ArgumentList '-S','localhost:8000','-t','public' -PassThru
Start-Sleep -Seconds 2
./vendor/bin/phpunit --testsuite=API
Stop-Process -Id $p.Id
```

### 5. Specific Test File
Run a single test file:
```bash
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

### 6. Verbose Output
Add `--verbose` flag for detailed output:
```bash
./vendor/bin/phpunit --testsuite=API --verbose
```

## Test Structure

- **`tests/Unit/`** - Unit tests (Domain layer, no external dependencies)
- **`tests/Integration/`** - Integration tests (Infrastructure layer, in-memory SQLite)
  - **Base Class:** `IntegrationTestCase` - Extends PHPUnit TestCase with Phinx migration support
  - All integration tests extend this base class for automatic schema setup
- **`tests/Integration/Api/`** - API tests (PHPUnit test suite)

## Prerequisites

- **Dependencies installed:** `composer install`
- **For API tests:** Dev server running on `localhost:8000`
- **For local app/API verification:** run `vendor/bin/phinx migrate` first

## Common Commands

**Quick validation (unit tests only):**
```bash
./vendor/bin/phpunit tests/Unit
```

**Full validation before PR:**
```bash
./vendor/bin/phpunit
```

**Test specific functionality:**
```bash
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

## Static Analysis (PHPStan)

Runs level 5 static analysis on `src/`:

```bash
vendor/bin/phpstan analyse
```

Pre-existing warnings are suppressed in `phpstan-baseline.neon`. Do not add new baseline entries for code you write — fix the issue instead.

PHPStan runs automatically in CI in parallel with unit tests.

## JavaScript Linting (ESLint)

Lints the frontend JS in `public/app/js/`:

```bash
npm run lint        # Check for errors
npm run lint:fix    # Auto-fix errors
```

ESLint runs automatically in CI on every push.

## Full Pre-PR Validation

Run all checks before opening a pull request:

```bash
./vendor/bin/phpunit          # All PHP tests
vendor/bin/phpstan analyse    # Static analysis
npm run lint                  # JS linting
```

## Notes

- Unit tests are fastest (no database setup required)
- Integration tests use in-memory SQLite with Phinx migrations
- API tests require a running web server
- All test data is automatically cleaned up after each test
