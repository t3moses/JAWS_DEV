# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Human-Readable Documentation

See `/docs` for developer docs (setup, architecture, API, deployment). Direct general questions there.

- `README.md` — Project overview
- `docs/SETUP.md` — Installation
- `docs/DEVELOPER_GUIDE.md` — Architecture & workflow
- `docs/API.md` — API endpoint documentation
- `docs/DEPLOYMENT.md` — Production deployment
- `docs/CONTRIBUTING.md` — Code style & PR process

## Skills

When performing any of the tasks below, **read the corresponding skill file first and follow it**:

| Task | Skill file |
|------|------------|
| Running tests / PHPUnit | `.claude/skills/test.md` |
| Debugging or fixing errors | `.claude/skills/troubleshooting.md` |
| Creating a git commit | `.claude/skills/conventional-commits.md` |
| Adding an API endpoint | `.claude/skills/add-endpoint.md` |
| Modifying the database schema | `.claude/skills/modify-schema.md` |
| Database operations (migrations, backup, query) | `.claude/skills/database-ops.md` |
| Deploying to production (AWS Lightsail) | `.claude/skills/deploy-lightsail.md` |
| Adding a ranking dimension | `.claude/skills/add-ranking.md` |
| Adding an assignment rule | `.claude/skills/add-rule.md` |

## Project Overview

JAWS is a PHP REST API for Nepean Sailing Club's Social Day Cruising program. Manages boat fleet, crew registration, and intelligent crew-to-boat assignment for seasonal sailing events.

**Architecture:** Clean Architecture (4 layers: Domain, Application, Infrastructure, Presentation)
**Database:** SQLite with Phinx migrations
**API Style:** REST/JSON with JWT authentication

## Development Commands

```bash
composer install                          # Install PHP dependencies
npm install                               # Install JS dependencies (ESLint)
vendor/bin/phinx migrate                  # Run migrations
php -S localhost:8000 -t public           # Dev server
./vendor/bin/phpunit                      # All tests
./vendor/bin/phpunit tests/Unit           # Unit tests only
./vendor/bin/phpunit --testsuite=API      # API tests (needs server)
npm run lint                              # Lint frontend JS
npm run lint:fix                          # Auto-fix lint errors
```

**Commit format:** `<type>: <description>` (types: feat, fix, docs, test, refactor, ci)

## Clean Architecture

Dependencies flow inward only: `Presentation → Infrastructure → Application → Domain`

**Domain layer has ZERO external dependencies.**

### Layer 1: Domain (`src/Domain/`) — Core business logic

- **Entities:** `Boat.php`, `Crew.php`, `User.php`
- **Value Objects:** `BoatKey`, `CrewKey`, `EventId`, `Rank` (immutable identifiers)
- **Enums:** `AvailabilityStatus` (0-3), `SkillLevel` (0-2), `AssignmentRule` (6 rules), `BoatRankDimension`, `CrewRankDimension`, `TimeSource`
- **Collections:** `Fleet.php`, `Squad.php` (in-memory)
- **Domain Services** ⚠️ CRITICAL:
  - `SelectionService.php` — Ranking & selection (CRC32 seeding, lexicographic sort, 3 capacity cases)
  - `AssignmentService.php` — Crew-to-boat optimization (6 constraint rules, greedy swapping)
  - `RankingService.php` — Multi-dimensional rank calculations + inline flex detection

### Layer 2: Application (`src/Application/`) — Use cases & ports

Key use cases:
- `Season/ProcessSeasonUpdateUseCase.php` ⚠️ MAIN PIPELINE — runs after every user action
- `Season/GenerateFlotillaUseCase.php`
- `Auth/` — Login, Register, Logout, GetSession
- `User/` — GetProfile, AddProfile, UpdateProfile
- `Boat/UpdateBoatAvailabilityUseCase.php`, `Crew/UpdateCrewAvailabilityUseCase.php`
- `Event/`, `Flotilla/` — CRUD/query use cases
- `Admin/` — user/crew/boat management, whitelist operations, commitment rank, notifications
- `Cron/SendCrewReminderUseCase.php`, `Cron/SendCrewListUseCase.php`

**Ports (interfaces):** `Port/Repository/` and `Port/Service/` (incl. `TransactionServiceInterface`) — contracts implemented by Infrastructure.

**DTOs:** `DTO/Request/` and `DTO/Response/` — typed input/output for use cases.

**Exceptions:** `Exception/` — domain-specific exceptions mapping to HTTP status codes.

### Layer 3: Infrastructure (`src/Infrastructure/`) — External adapters

- `Persistence/SQLite/` — `BoatRepository`, `CrewRepository`, `EventRepository`, `SeasonRepository`, `UserRepository`, `Connection`
- `Service/` — `PhpMailerEmailService`, `AwsSesEmailService`, `MailjetEmailService`, `EmailTemplateService`, `ICalendarService`, `SystemTimeService`, `JwtTokenService`, `PhpPasswordService`, `DatabaseTransactionService`

### Layer 4: Presentation (`src/Presentation/`) — HTTP/REST

- **Controllers:** `AuthController`, `UserController`, `EventController`, `AvailabilityController`, `AssignmentController`, `AdminController`
- **Middleware:** `JwtAuthMiddleware`, `ErrorHandlerMiddleware`, `CorsMiddleware`
- **Router:** `Router.php` (pattern matching with parameter extraction)

## Season Update Pipeline

**Trigger:** After every user action (registration, availability update)

1. **Load** — Fetch boats, crews, events, config; build Fleet/Squad collections
2. **Selection** (`SelectionService`) — Rank, shuffle (`crc32($eventId)`), capacity match (3 cases)
3. **Consolidation** — Form flotilla; separate crewed boats from waitlist
4. **Assignment** (`AssignmentService`) — **Next event only**: iterative constraint-based swapping (6 rules)
5. **Persist** — Update crew statuses (GUARANTEED), history, save flotilla JSON

## Database Schema

**Location:** `database/jaws.db` | **Migrations:** `database/migrations/`

12 tables:
1. `boats` — display_name, owner_*, capacity, assistance_required, ranking
2. `crews` — name, partner_key, email, skill, membership_number, ranking
3. `events` — event_id, event_date, start/finish_time, status
4. `boat_availability` — berths per boat per event
5. `crew_availability` — status (0-3) per crew per event
6. `boat_history` — participation ('Y' or '')
7. `crew_history` — crew-to-boat per event
8. `crew_whitelist` — crew preferences for specific boats
9. `season_config` — singleton config (dates, times, blackout windows)
10. `flotillas` — JSON assignments
11. `users` — authentication credentials (email, password_hash, is_admin, boat_key, crew_key)
12. `cron_notifications` — idempotent email tracking, UNIQUE(event_id, type)

Features: FK constraints, CASCADE deletes, composite indexes, WAL mode, auto-update triggers.

## Ranking System

**Boats:** `[flexibility, absence]` (2D)
**Crews:** `[commitment, membership, absence]` (3D)

Compared lexicographically, higher = higher priority (sorted descending). Ties broken by `srand(crc32($eventId))`.

- `flexibility` — boats only: 0 if flex (owner also crew), else 1
- `absence` — count of past no-shows (deprioritizes unreliable participants)
- `commitment` — 3=assigned, 2=available, 1=admin penalty, 0=unavailable/withdrawn
- `membership` — 0=valid NSC membership, 1=invalid

## Assignment Optimization

**Location:** `src/Domain/Service/AssignmentService.php` | **Scope:** Next event only

Rules (priority order): ASSIST, WHITELIST, HIGH_SKILL, LOW_SKILL, PARTNER, REPEAT

For each rule: find highest-loss crew → find best-grad swap → swap if improvement → lock crew (prevents thrashing).

## API Endpoints

**Base:** `/api` | **Entry:** `public/index.php` | **Routes:** `config/routes.php`

### Public

| Method | Path | Controller |
|--------|------|------------|
| GET | /api/status | EventController::getStatus |
| GET | /api/events | EventController::getAll |
| GET | /api/events/{id} | EventController::getOne |
| GET | /api/flotillas | EventController::getAllFlotillas |
| POST | /api/auth/register | AuthController::register |
| POST | /api/auth/login | AuthController::login |

### Authenticated (`Authorization: Bearer {token}`)

| Method | Path | Controller |
|--------|------|------------|
| GET | /api/auth/session | AuthController::getSession |
| POST | /api/auth/logout | AuthController::logout |
| GET | /api/users/me | UserController::getProfile |
| POST | /api/users/me | UserController::addProfile |
| PATCH | /api/users/me | UserController::updateProfile |
| GET | /api/users/me/availability | AvailabilityController::getCrewAvailability |
| PATCH | /api/users/me/availability | AvailabilityController::updateAvailability |
| GET | /api/assignments | AssignmentController::getUserAssignments |
| GET | /api/admin/config | AdminController::getConfig |
| PATCH | /api/admin/config | AdminController::updateConfig |
| GET | /api/admin/matching/{eventId} | AdminController::getMatchingData |
| GET | /api/admin/participants/{eventId} | AdminController::getParticipantEmails |
| POST | /api/admin/notifications/{eventId}/custom | AdminController::sendCustomNotification |
| GET | /api/admin/users | AdminController::getUsers |
| GET | /api/admin/users/{userId} | AdminController::getUser |
| PATCH | /api/admin/users/{userId}/admin | AdminController::setUserAdmin |
| GET | /api/admin/crews | AdminController::getAllCrews |
| PATCH | /api/admin/crews/{crewKey} | AdminController::updateCrewProfile |
| POST | /api/admin/crews/{crewKey}/whitelist/{boatKey} | AdminController::addToWhitelist |
| DELETE | /api/admin/crews/{crewKey}/whitelist/{boatKey} | AdminController::removeFromWhitelist |
| PATCH | /api/admin/crews/{crewKey}/commitment-rank | AdminController::setCrewCommitmentRank |
| GET | /api/admin/boats | AdminController::getAllBoats |

**PATCH /api/users/me/availability:** Auto-detects boat/crew/flex role. Updates boat berths, crew status, or both. Triggers `ProcessSeasonUpdateUseCase`. Response includes `updated` array (e.g., `["boat", "crew"]`).

## Key Architectural Concepts

**Dependency Inversion:** Application layer defines interfaces (`Port/`), Infrastructure implements them. Use cases depend on interfaces, enabling storage backend swap without touching business logic.

**Flex:** Boat owners who also register as crew. Sets boat `flexibility` rank to 0. Prevents double-counting in capacity matching. Detection logic is inlined in `RankingService`.

**Availability States:** UNAVAILABLE(0), AVAILABLE(1), GUARANTEED(2), WITHDRAWN(3)

**Blackout Logic:** Registration blocked during events (default 10:00-18:00). Configured in `season_config`. Throws `BlackoutWindowException` → HTTP 403.

**Determinism:** `srand(crc32($eventId))` seeds shuffle. Same inputs always produce same assignments.

**Capacity Matching** (`SelectionService::cut()`):
1. Too few crews → partial boat crewing, remainder waitlisted
2. Too many crews → all boats filled, excess crews waitlisted
3. Perfect fit → all boats fully crewed

## Testing

- `tests/Unit/` — Domain unit tests (no database)
- `tests/Integration/` — SQLite integration tests (extend `IntegrationTestCase` for Phinx auto-setup)
- `tests/Integration/Api/` — API tests (requires dev server on port 8000)

## Common Patterns

**Error Handling** via `ErrorHandlerMiddleware`:
- `*NotFoundException` → 404 | `ValidationException` → 400 | `BlackoutWindowException` → 403 | Generic → 500

**DI:** All dependencies wired in `config/container.php` via constructor injection.

**Repository:** Interfaces in `Port/Repository/`, implementations in `Persistence/SQLite/`. Methods: `save()`, `findByKey()`, `findAll()`, `findAvailableForEvent()`.

**Entity Access:** Use Value Objects — `$repo->findByKey(new BoatKey('sailaway'))`

**Working with Time:** `TimeServiceInterface::getCurrentTime()` / `isInBlackoutWindow()`. Supports simulated time via `season_config.source` (production|simulated).

## Configuration

**Key env vars:**
- `JWT_SECRET` — 32+ chars, required
- `DB_PATH` — default: `database/jaws.db`
- `APP_ENV` — production/development
- `JWT_EXPIRATION_MINUTES` — default: 60
- `SMTP_*` — email settings
- `CORS_ALLOWED_ORIGINS` — comma-separated origins

## Critical File Paths

```
src/Domain/Service/SelectionService.php                        ⚠️ CRITICAL ALGORITHM
src/Domain/Service/AssignmentService.php                       ⚠️ CRITICAL ALGORITHM
src/Application/UseCase/Season/ProcessSeasonUpdateUseCase.php  ⚠️ MAIN PIPELINE
config/container.php                                           Dependency injection
config/routes.php                                              API routes
database/migrations/                                           Phinx migrations
legacy/                                                        Original codebase (reference only)
```

## Critical Success Factors

1. **Preserve Business Logic:** Selection and Assignment algorithms must produce identical results to legacy
2. **Maintain Determinism:** Same inputs always produce same outputs
3. **Respect Layer Boundaries:** Never violate dependency direction (outer → inner only)
4. **Test Thoroughly:** Unit → Integration → API
