# Copilot Instructions for JAWS

## Repository Overview

**JAWS** is a PHP REST API for boat fleet and crew assignment management using **Clean Architecture** (Domain → Application → Infrastructure → Presentation).

- **Tech**: PHP 8.1+ (CI: 8.5), SQLite, Phinx, PHPUnit (~15k lines, 350+ tests)
- **Critical**: SelectionService & AssignmentService algorithms must be preserved
- **Rule**: Outer layers depend on inner only. Domain has ZERO external dependencies.

## Critical Build Instructions

### 1. Install Dependencies

```bash
composer install --prefer-dist --no-progress --no-interaction
```

**If using PHP < 8.4**: The lock file requires PHP 8.4+ (matching CI). Use `--ignore-platform-reqs` if needed:

```bash
composer install --prefer-dist --no-progress --no-interaction --ignore-platform-reqs
```

**NEVER run `composer update`** - it will break CI by downgrading packages to match your local PHP version.

### 2. Initialize Database

```bash
vendor/bin/phinx migrate     # Creates database/jaws.db (~200KB)
vendor/bin/phinx seed:run    # Optional: test data
```

### 3. Configure Environment

```bash
# Mac/Linux
cp .env.example .env

# Windows (PowerShell)
Copy-Item .env.example .env

# Set JWT_SECRET (min 32 chars, REQUIRED)
```

### 4. Run Development Server

```bash
php -S localhost:8000 -t public  # Required for API tests
curl http://localhost:8000/api/events  # Test
```

### 5. Run Tests

```bash
# Unit tests (fast, no DB needed)
vendor/bin/phpunit tests/Unit

# Integration tests (DB-backed, uses in-memory SQLite test setup)
vendor/bin/phpunit tests/Integration

# All tests
vendor/bin/phpunit                    # Runs all test suites

# Specific test file
vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php

# API tests (start server first)
# Mac/Linux
php -S localhost:8000 -t public > /dev/null 2>&1 & SERVER_PID=$!; sleep 2
vendor/bin/phpunit --testsuite=API
kill $SERVER_PID

# Windows (PowerShell)
$p = Start-Process php -ArgumentList '-S','localhost:8000','-t','public' -PassThru
Start-Sleep -Seconds 2
vendor/bin/phpunit --testsuite=API
Stop-Process -Id $p.Id
```

Run `vendor/bin/phinx migrate` before local application/API testing. Note: PHPUnit integration tests bootstrap their own in-memory SQLite schema.

## Project Layout

### Directory Structure

```
src/
├── Domain/              # Pure business logic (NO external dependencies)
│   ├── Entity/          # Boat, Crew, Event
│   ├── Service/         # SelectionService, AssignmentService, RankingService (CRITICAL)
│   ├── ValueObject/     # BoatKey, CrewKey, EventId, Rank
│   └── Enum/            # AvailabilityStatus, SkillLevel, AssignmentRule
├── Application/         # Use cases & ports (depends on Domain only)
│   ├── UseCase/         # ProcessSeasonUpdateUseCase, UpdateAvailabilityUseCase
│   ├── Port/            # Repository & Service interfaces
│   └── DTO/             # Request/Response data transfer objects
├── Infrastructure/      # External adapters (DB, email, calendar)
│   ├── Persistence/     # SQLite repositories
│   └── Service/         # AwsSesEmailService, ICalendarService
└── Presentation/        # HTTP API layer
    ├── Controller/      # EventController, AvailabilityController
    ├── Middleware/      # JwtAuthMiddleware, ErrorHandlerMiddleware
    └── Router.php       # Route definitions

config/                  # DI container, routes, config
database/migrations/     # Phinx migration files (add new files; do not modify historical migration timestamps)
tests/{Unit,Integration}/  # PHPUnit test suites
public/                  # Web root (index.php, frontend app/)
```

### Key Files

**Config**: `composer.json`, `phinx.php`, `.env`, `config/{routes,container}.php`  
**Critical (preserve)**: `src/Domain/Service/{Selection,Assignment}Service.php`, `src/Application/UseCase/Season/ProcessSeasonUpdateUseCase.php`

## CI/CD Pipeline

`.github/workflows/ci.yml` runs `build` and `unit-tests` on push, plus `integration-tests` on pull requests. API/E2E checks run in `.github/workflows/deploy.yml` (`e2e-tests` job). Uses PHP 8.5 in CI.

**Common failures**: Missing DB migrations, server not started. DO NOT modify composer.lock unless using PHP 8.4+.

## Environment Requirements

**Required extensions**: pdo, pdo_sqlite, sqlite3, curl, mbstring, openssl
**Optional**: Xdebug (for debugging), MailHog (for local email testing)

## Common Issues & Solutions

**Composer install fails (PHP < 8.4)**: Use `composer install --ignore-platform-reqs` - lock file requires PHP 8.4+  
**DB permission errors (Mac/Linux)**: `chmod 775 database && chmod 664 database/jaws.db`  
**DB permission errors (Windows)**: ensure your user has write access to `database/` and `database/jaws.db`  
**JWT 401 errors**: Verify `.env` has JWT_SECRET (min 32 chars)  
**Migration "already exists"**: Check `vendor/bin/phinx status`, rollback if needed  
**Port in use (Mac/Linux)**: `lsof -ti:8000 | xargs kill -9` or use different port  
**Port in use (Windows PowerShell)**: `Get-NetTCPConnection -LocalPort 8000 | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }`  
**Local test failures (Mac/Linux)**: `rm database/jaws.db && vendor/bin/phinx migrate && rm -rf .phpunit.cache`  
**Local test failures (Windows PowerShell)**: `Remove-Item database\jaws.db -ErrorAction SilentlyContinue; vendor/bin/phinx migrate; Remove-Item .phpunit.cache -Recurse -Force -ErrorAction SilentlyContinue`

## Architecture Rules

**Layer boundaries**: Domain (no imports) ← Application ← Infrastructure, Presentation → Application only  
**Wrong**: Domain importing PDO/repositories. **Right**: Application ports, Infrastructure implements.

**Critical algorithms**: DO NOT modify core logic in Selection/AssignmentService. Add features around them. ALWAYS run `tests/Unit/Domain/Service/SelectionServiceTest.php` after changes. Verify deterministic output.

## Code Quality

**Style**: Follow PSR-12 (4 spaces, strict types, type hints)

**Testing**: Write tests for new features. Maintain existing test coverage.
- Unit tests for Domain layer (pure business logic)
- Integration tests for Infrastructure layer (database interactions)
- API tests for Presentation layer (HTTP endpoints)

**Safety**: Preserve existing functionality. If unsure, ask before modifying critical code.

## Pre-PR Checklist

1. `vendor/bin/phpunit` passes
2. `vendor/bin/phinx status` shows all "up"
3. API responds locally (`curl http://localhost:8000/api/events`)
4. Relevant GitHub Actions workflow passes for the event type (push: build + unit; pull_request: build + unit + integration)
5. Code follows PSR-12 (4 spaces, strict types, type hints)

Update docs if needed: `README.md`, `CLAUDE.md`, `docs/{DEVELOPER_GUIDE,API,CONTRIBUTING}.md`

## Quick Reference Commands

```bash
# Setup from scratch
composer install --prefer-dist --no-progress --no-interaction
vendor/bin/phinx migrate

# Mac/Linux
cp .env.example .env && nano .env  # Set JWT_SECRET

# Windows (PowerShell)
Copy-Item .env.example .env
notepad .env

# Development workflow
php -S localhost:8000 -t public &           # Start server
vendor/bin/phpunit tests/Unit               # Quick test
vendor/bin/phinx create MyMigrationName     # New migration
vendor/bin/phinx migrate                    # Apply migrations

# Database operations
vendor/bin/phinx rollback                   # Undo last migration
vendor/bin/phinx status                     # Check migration status
sqlite3 database/jaws.db "SELECT * FROM boats LIMIT 5"  # Query DB

# Cleanup
# Mac/Linux
rm database/jaws.db && vendor/bin/phinx migrate  # Reset DB
rm -rf vendor && composer install                 # Reinstall deps

# Windows (PowerShell)
Remove-Item database\jaws.db -ErrorAction SilentlyContinue
vendor/bin/phinx migrate
Remove-Item vendor -Recurse -Force
composer install
```

## Commit Message Format

This project uses **Conventional Commits** specification. Always follow this format:

```
<type>[optional scope]: <description>
```

**Types**: feat, fix, docs, style, refactor, perf, test, build, ci, chore

**Examples**:
```bash
feat: add crew notes field to database schema
fix: prevent duplicate crew assignments on same boat
docs: update API documentation for availability endpoint
test: add integration tests for AssignmentService
```

**Rules**:
- Use lowercase for type and description
- No period at end of description
- Use imperative mood ("add" not "added")
- Keep description under 72 characters

## Documentation

For detailed information, see:
- **README.md** - Project overview and quick start
- **docs/SETUP.md** - Installation guide for new developers
- **docs/DEVELOPER_GUIDE.md** - Architecture and development workflow
- **docs/API.md** - Complete API endpoint documentation
- **docs/CONTRIBUTING.md** - Code style and Git workflow
- **CLAUDE.md** - Extended technical specifications for AI assistants

## Trust These Instructions

These instructions have been verified by running all commands on a clean clone of the repository. If you encounter issues not documented here, it's likely a new problem - investigate and update this file with your findings.
