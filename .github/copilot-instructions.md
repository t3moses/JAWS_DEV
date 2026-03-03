# Copilot Instructions for JAWS

## Purpose

JAWS is a PHP REST API for boat fleet and crew assignment management using Clean Architecture.

- Stack: PHP 8.1+ (CI: 8.5), SQLite, Phinx, PHPUnit
- Architecture: Domain → Application → Infrastructure → Presentation
- Rule: outer layers depend on inner layers only

## Non-Negotiable Rules

1. Preserve core algorithm behavior in:
   - `src/Domain/Service/SelectionService.php`
   - `src/Domain/Service/AssignmentService.php`
2. Domain layer must have zero external dependencies.
3. Do not violate dependency direction (no infra imports in Domain/Application).
4. Never run `composer update` for routine setup/fixes.
5. Do not modify historical migration timestamps/files; add new migrations only.

## Critical Files

- `src/Domain/Service/SelectionService.php` (critical)
- `src/Domain/Service/AssignmentService.php` (critical)
- `src/Application/UseCase/Season/ProcessSeasonUpdateUseCase.php` (main pipeline)
- `config/container.php` (DI wiring)
- `config/routes.php` (HTTP routes)
- `database/migrations/` (schema evolution)

## Setup (Minimal)

```bash
composer install --prefer-dist --no-progress --no-interaction
vendor/bin/phinx migrate
```

If local PHP is below lock requirements, use:

```bash
composer install --prefer-dist --no-progress --no-interaction --ignore-platform-reqs
```

Environment:

- Copy `.env.example` to `.env`
- Set `JWT_SECRET` (32+ chars)

## Run / Test (Minimal)

```bash
php -S localhost:8000 -t public
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration
vendor/bin/phpunit
```

When changing logic near selection/assignment behavior, run:

```bash
vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

## Quality Expectations

- Follow PSR-12 (4 spaces, strict types, type hints)
- Keep changes minimal and architecture-safe
- Add/update tests for behavior changes in affected layer
- Preserve deterministic behavior in assignment/selection flows

## Common Pitfalls

- CI/local mismatch from PHP version vs lock file
- DB not migrated before local API validation
- Missing/weak `JWT_SECRET` causing auth failures
- Port `8000` already in use

## Commit Format

Use Conventional Commits:

```text
<type>[optional scope]: <description>
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`

## Source of Truth

For full detail, prefer these files over duplicating instructions:

- `CLAUDE.md`
- `README.md`
- `docs/SETUP.md`
- `docs/DEVELOPER_GUIDE.md`
- `docs/API.md`
- `docs/CONTRIBUTING.md`