<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Application\Exception\ValidationException;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\FlotillaNotFoundException;
use App\Application\Exception\BlackoutWindowException;
use App\Application\Exception\InvalidCredentialsException;
use App\Application\Exception\UserAlreadyExistsException;
use App\Application\Exception\InvalidTokenException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Exception\UnauthorizedException;
use App\Presentation\Response\JsonResponse;

/**
 * Error Handler Middleware
 *
 * Catches exceptions and formats them as JSON responses.
 */
class ErrorHandlerMiddleware
{
    /**
     * Handle exception and return appropriate JSON response
     */
    public function handleException(\Throwable $e): JsonResponse
    {
        // Validation errors (400 Bad Request)
        if ($e instanceof ValidationException) {
            return JsonResponse::error(
                'Validation failed',
                400,
                $e->getErrors()
            );
        }

        // Not found errors (404 Not Found)
        if ($e instanceof BoatNotFoundException ||
            $e instanceof CrewNotFoundException ||
            $e instanceof EventNotFoundException ||
            $e instanceof FlotillaNotFoundException) {
            return JsonResponse::notFound($e->getMessage());
        }

        // Blackout window (403 Forbidden)
        if ($e instanceof BlackoutWindowException) {
            return JsonResponse::error($e->getMessage(), 403);
        }

        // Authentication errors (401 Unauthorized)
        if ($e instanceof InvalidCredentialsException ||
            $e instanceof InvalidTokenException) {
            return JsonResponse::error($e->getMessage(), 401);
        }

        // User already exists (409 Conflict)
        if ($e instanceof UserAlreadyExistsException) {
            return JsonResponse::error($e->getMessage(), 409);
        }

        // Lock timeout / concurrent update (409 Conflict)
        if ($e instanceof \RuntimeException && $e->getCode() === 409) {
            return JsonResponse::error($e->getMessage(), 409);
        }

        // Weak password (400 Bad Request)
        if ($e instanceof WeakPasswordException) {
            return JsonResponse::error($e->getMessage(), 400);
        }

        // Unauthorized access (403 Forbidden)
        if ($e instanceof UnauthorizedException) {
            return JsonResponse::error($e->getMessage(), 403);
        }

        // Log unexpected errors
        $this->logError($e);

        // Generic server error (500 Internal Server Error)
        // Don't expose internal error details in production
        $message = $this->isDebugMode()
            ? $e->getMessage() . "\n" . $e->getTraceAsString()
            : 'An unexpected error occurred';

        return JsonResponse::serverError($message);
    }

    /**
     * Log error to error log
     */
    private function logError(\Throwable $e): void
    {
        error_log(sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    /**
     * Check if debug mode is enabled
     */
    private function isDebugMode(): bool
    {
        return getenv('APP_DEBUG') === 'true';
    }
}
