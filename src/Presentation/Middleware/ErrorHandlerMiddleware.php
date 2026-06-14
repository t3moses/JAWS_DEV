<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Application\Exception\AccountDisabledException;
use App\Application\Exception\ValidationException;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\FlotillaNotFoundException;
use App\Application\Exception\BlackoutWindowException;
use App\Application\Exception\InvalidCredentialsException;
use App\Application\Exception\InvalidResetTokenException;
use App\Application\Exception\UserAlreadyExistsException;
use App\Application\Exception\InvalidTokenException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Exception\UnauthorizedException;
use App\Presentation\Response\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * Error Handler Middleware
 *
 * Catches exceptions and formats them as JSON responses.
 */
class ErrorHandlerMiddleware
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Handle exception and return appropriate JSON response
     */
    public function handleException(\Throwable $e): JsonResponse
    {
        // Validation errors (400 Bad Request)
        if ($e instanceof ValidationException) {
            $this->logger->info('http.validation_error', [
                'errors' => $e->getErrors(),
            ]);
            return JsonResponse::error(
                'Validation failed',
                400,
                $e->getErrors()
            );
        }

        // Not found errors (404 Not Found)
        if (
            $e instanceof BoatNotFoundException ||
            $e instanceof CrewNotFoundException ||
            $e instanceof EventNotFoundException ||
            $e instanceof FlotillaNotFoundException
        ) {
            $this->logger->info('http.not_found', [
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            return JsonResponse::notFound($e->getMessage());
        }

        // Disabled / suspended account (403 Forbidden)
        if ($e instanceof AccountDisabledException) {
            $this->logger->warning('http.account_disabled', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 403);
        }

        // Blackout window (403 Forbidden)
        if ($e instanceof BlackoutWindowException) {
            $this->logger->info('http.blackout_window', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 403);
        }

        // Authentication errors (401 Unauthorized)
        if (
            $e instanceof InvalidCredentialsException ||
            $e instanceof InvalidTokenException
        ) {
            $this->logger->warning('http.auth_failure', [
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 401);
        }

        // User already exists (409 Conflict)
        if ($e instanceof UserAlreadyExistsException) {
            $this->logger->warning('http.user_already_exists', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 409);
        }

        // Lock timeout / concurrent update (409 Conflict)
        if ($e instanceof \RuntimeException && $e->getCode() === 409) {
            return JsonResponse::error($e->getMessage(), 409);
        }

        // Weak password (400 Bad Request)
        if ($e instanceof WeakPasswordException) {
            $this->logger->info('http.weak_password', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 400);
        }

        // Invalid password reset token (400 Bad Request)
        if ($e instanceof InvalidResetTokenException) {
            $this->logger->info('http.invalid_reset_token', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 400);
        }

        // Unauthorized access (403 Forbidden)
        if ($e instanceof UnauthorizedException) {
            $this->logger->warning('http.unauthorized', [
                'message' => $e->getMessage(),
            ]);
            return JsonResponse::error($e->getMessage(), 403);
        }

        // Generic server error (500 Internal Server Error)
        // Don't expose internal error details in production
        $this->logger->error('http.unhandled_exception', [
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'trace'           => $e->getTraceAsString(),
        ]);

        $message = $this->isDebugMode()
            ? $e->getMessage() . "\n" . $e->getTraceAsString()
            : 'An unexpected error occurred';

        return JsonResponse::serverError($message);
    }

    /**
     * Check if debug mode is enabled
     */
    private function isDebugMode(): bool
    {
        return getenv('APP_DEBUG') === 'true';
    }
}
