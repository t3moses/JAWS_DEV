# API Test Suite

This directory contains PHPUnit-based API endpoint tests for the JAWS REST API.

## Test Files

- **[ApiTestTrait.php](ApiTestTrait.php)** - Shared test utilities (HTTP requests, test user creation, cleanup)
- **[EventApiTest.php](EventApiTest.php)** - Tests for event endpoints (6 tests)
- **[AuthApiTest.php](AuthApiTest.php)** - Tests for authentication endpoints (9 tests)
- **[UserProfileApiTest.php](UserProfileApiTest.php)** - Tests for user profile endpoints (11 tests)
- **[AvailabilityApiTest.php](AvailabilityApiTest.php)** - Tests for availability endpoints (7 tests)
- **[AssignmentApiTest.php](AssignmentApiTest.php)** - Tests for assignment endpoints (2 tests)
- **[AdminApiTest.php](AdminApiTest.php)** - Tests for admin endpoints (4 tests)

**Total: 39 API tests**

## Running Tests

### Prerequisites
Start the development server before running API tests:
```bash
php -S localhost:8000 -t public &
```

### Run Tests

```bash
# Run all API tests
./vendor/bin/phpunit --testsuite=API

# Run specific test file
./vendor/bin/phpunit tests/Integration/Api/EventApiTest.php

# Run specific test method
./vendor/bin/phpunit --filter testGetAllEvents

# Run with verbose output
./vendor/bin/phpunit --testsuite=API --verbose

# Run with detailed debug output
./vendor/bin/phpunit --testsuite=API --debug
```

## Test Structure

### Example Test

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

class ExampleApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testExample(): void
    {
        // Create test user with authentication token
        $testData = $this->createTestCrew($this->baseUrl);

        // Make authenticated request
        $response = $this->makeRequest('GET', "{$this->baseUrl}/endpoint", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // Assertions
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        // Cleanup test user
        $this->cleanupTestUser($testData['userId']);
    }
}
```

### ApiTestTrait Helper Methods

#### HTTP Requests
- `makeRequest(string $method, string $url, ?array $body, ?array $headers): array`
  - Make HTTP request and return `['status' => int, 'body' => array]`

#### Test Data Generation
- `makeUniqueEmail(string $prefix): string` - Generate unique email address
- `makeUniqueSuffix(): string` - Generate unique timestamp suffix

#### Test User Creation
- `createTestCrew(string $baseUrl): array` - Create crew account and return `['token', 'userId', 'firstName', 'lastName']`
- `createTestBoatOwner(string $baseUrl): array` - Create boat owner account and return `['token', 'userId', 'ownerFirstName', 'ownerLastName']`

#### Cleanup
- `cleanupTestUser(?int $userId): void` - Delete test user from database

## Writing New Tests

1. Create new test class extending `PHPUnit\Framework\TestCase`
2. Add `use ApiTestTrait;` to access helper methods
3. Set `private string $baseUrl = 'http://localhost:8000/api';`
4. Write test methods with `public function test*(): void` signature
5. Use `$this->assertEquals()`, `$this->assertTrue()`, etc. for assertions
6. Always clean up test users with `cleanupTestUser()` in teardown or after test

### Test Naming Convention

- Test methods: `testVerbNoun()` (e.g., `testGetAllEvents`, `testUpdateCrewProfile`)
- Test files: `{Domain}ApiTest.php` (e.g., `EventApiTest.php`, `UserProfileApiTest.php`)

## CI/CD Integration

These tests run automatically in the deployment workflow before deployment:

- Job: `e2e-tests` in [.github/workflows/deploy.yml](../../../.github/workflows/deploy.yml)
- Runs after pre-deployment checks and database setup
- Starts PHP development server automatically
- Blocks deployment if any API tests fail

## Test Categories

The test suite covers multiple areas:

### Endpoint Coverage (100%)
- All 19 API endpoints have at least one test
- Public endpoints (events, flotillas)
- Authentication endpoints (register, login, session, logout)
- User profile endpoints (get, add, update)
- Availability endpoints (get, update)
- Assignment endpoints (get)
- Admin endpoints (matching, notifications, config)

### Validation & Error Handling
- Input validation (missing fields, invalid formats)
- Boundary value testing (skill levels, berth counts)
- Duplicate data handling (email uniqueness)
- Authentication failures (invalid credentials, missing tokens)

### Security Testing
- SQL injection attempts
- XSS attack prevention
- Path traversal protection
- URL encoding handling
- Special character handling

### Business Logic
- Flex status (users with both boat and crew profiles)
- Flotilla generation triggers
- Assignment creation with real data
- Idempotent operations

## Benefits Over Legacy Script

The new PHPUnit-based structure provides:

1. **Better organization** - Tests split by domain (39 tests across 7 files vs 1,363-line script)
2. **PHPUnit integration** - Run specific tests, better assertions, IDE support
3. **Reusable utilities** - ApiTestTrait provides common test helpers
4. **Test isolation** - Proper setup/teardown, automatic cleanup
5. **Better reporting** - Clear test names, detailed failure messages
6. **Faster development** - Run individual tests during development
7. **Comprehensive coverage** - 100% endpoint coverage + validation + security tests

## Migration Notes

This test suite replaces the legacy `tests/Integration/api_test.php` script (deleted as of 2026-02-06).

All 24 tests from the legacy script have been migrated to PHPUnit test classes. Since migration:
- Added 15 new tests for complete endpoint coverage (100%)
- Added validation and security tests
- Enhanced edge case testing
- **Current total: 39 tests** (24 original + 15 new)
