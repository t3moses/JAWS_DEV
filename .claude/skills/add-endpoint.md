# Add API Endpoint Skill

Step-by-step guide for adding a new REST API endpoint to the JAWS application following Clean Architecture principles.

## Workflow Steps

### 1. Create Use Case

**Location:** `src/Application/UseCase/{Context}/{Action}UseCase.php`

**Example:**
```php
namespace App\Application\UseCase\Event;

class GetEventDetailsUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository
    ) {}

    public function execute(string $eventId): EventResponse
    {
        $event = $this->eventRepository->findById(new EventId($eventId));

        if (!$event) {
            throw new EventNotFoundException("Event not found: $eventId");
        }

        return EventResponse::fromEntity($event);
    }
}
```

### 2. Create Request/Response DTOs

**Location:** `src/Application/DTO/Request|Response/*.php`

**Request DTO Example:**
```php
namespace App\Application\DTO\Request;

class UpdateEventRequest
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $status
    ) {}
}
```

**Response DTO Example:**
```php
namespace App\Application\DTO\Response;

class EventResponse
{
    public static function fromEntity(Event $event): self
    {
        return new self(
            eventId: $event->getEventId()->getValue(),
            status: $event->getStatus()
        );
    }
}
```

### 3. Implement Controller Method

**Location:** `src/Presentation/Controller/*.php`

**Example:**
```php
namespace App\Presentation\Controller;

class EventController
{
    public function __construct(
        private GetEventDetailsUseCase $getEventDetailsUseCase
    ) {}

    public function getEventDetails(array $params): JsonResponse
    {
        try {
            $eventId = $params['id'] ?? throw new ValidationException('Event ID required');
            $response = $this->getEventDetailsUseCase->execute($eventId);

            return JsonResponse::success($response);
        } catch (EventNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        }
    }
}
```

### 4. Add Route

**Location:** `config/routes.php`

**Example:**
```php
[
    'method' => 'GET',
    'path' => '/api/events/{id}/details',
    'controller' => EventController::class,
    'action' => 'getEventDetails',
    'auth' => true,  // or false for public endpoints
],
```

### 5. Wire Dependencies

**Location:** `config/container.php`

**Example:**
```php
// Use Case
$container->set(GetEventDetailsUseCase::class, fn() => new GetEventDetailsUseCase(
    $container->get(EventRepositoryInterface::class)
));

// Controller
$container->set(EventController::class, fn() => new EventController(
    $container->get(GetEventDetailsUseCase::class)
));
```

### 6. Write Tests

**Location:** `tests/Integration/Api/`

**Example:**
```php
namespace Tests\Integration\Api;

use Tests\Integration\IntegrationTestCase;

class EventApiTest extends IntegrationTestCase
{
    public function testGetEventDetails(): void
    {
        // Arrange
        $eventId = 'test-event-001';
        $token = $this->getAuthToken();

        // Act
        $response = $this->makeRequest('GET', "/api/events/{$eventId}/details", [
            'headers' => ['Authorization' => "Bearer $token"]
        ]);

        // Assert
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($eventId, $response['data']['eventId']);
    }
}
```

### 7. Update Postman Collection

**Location:** `tests/JAWS_API.postman_collection.json`

Add a new request to the appropriate folder in the Postman collection for manual testing.

## Checklist

- [ ] Use case created in `src/Application/UseCase/`
- [ ] Request DTO created (if needed) in `src/Application/DTO/Request/`
- [ ] Response DTO created in `src/Application/DTO/Response/`
- [ ] Controller method implemented in `src/Presentation/Controller/`
- [ ] Route added to `config/routes.php`
- [ ] Dependencies wired in `config/container.php`
- [ ] API test created in `tests/Integration/Api/`
- [ ] Postman collection updated
- [ ] All tests passing (`./vendor/bin/phpunit`)

## Clean Architecture Reminders

- **Use Cases** should contain business logic orchestration
- **Controllers** should only handle HTTP concerns (parsing requests, formatting responses)
- **DTOs** should be simple data containers with no logic
- **Always use dependency injection** - no `new` keyword in controllers/use cases
- **Follow the dependency direction:** Presentation → Infrastructure → Application → Domain

## Common Patterns

**Authentication Required:**
- Set `'auth' => true` in route
- Access user via `$request['user']` in controller

**Public Endpoint:**
- Set `'auth' => false` in route
- No authentication required

**Path Parameters:**
- Define in route: `/api/events/{id}`
- Access in controller: `$params['id']`

**Error Handling:**
- Throw appropriate exceptions (e.g., `ValidationException`, `NotFoundException`)
- `ErrorHandlerMiddleware` will automatically map to HTTP status codes
