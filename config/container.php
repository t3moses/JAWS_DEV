<?php

declare(strict_types=1);

/**
 * Dependency Injection Container
 *
 * This file configures all dependencies for the application following
 * the Dependency Inversion Principle. Controllers receive use cases,
 * use cases receive repositories/services (via interfaces), and
 * repositories/services receive concrete implementations.
 */

use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\SeasonRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\Port\Service\CalendarServiceInterface;
use App\Application\Port\Service\TimeServiceInterface;
use App\Application\Port\Service\TokenServiceInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use App\Application\Port\Service\TransactionServiceInterface;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\EventRepository;
use App\Infrastructure\Persistence\SQLite\SeasonRepository;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Infrastructure\Service\PhpMailerEmailService;
use App\Infrastructure\Service\EmailTemplateService;
use App\Infrastructure\Service\ICalendarService;
use App\Infrastructure\Service\SystemTimeService;
use App\Infrastructure\Service\JwtTokenService;
use App\Infrastructure\Service\PhpPasswordService;
use App\Infrastructure\Service\DatabaseTransactionService;
use App\Domain\Service\SelectionService;
use App\Domain\Service\AssignmentService;
use App\Domain\Service\RankingService;

// Simple service container
class Container
{
    private array $services = [];
    private array $factories = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new Exception("Service '{$id}' not found in container");
        }

        $this->services[$id] = $this->factories[$id]($this);
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->services[$id]);
    }
}

// Create container
$container = new Container();

// Load configuration for services that need it
$config = require __DIR__ . '/config.php';

// =======================
// Infrastructure Layer
// =======================

// Repositories (Persistence)
$container->set(BoatRepositoryInterface::class, function () {
    return new BoatRepository();
});

$container->set(CrewRepositoryInterface::class, function () {
    return new CrewRepository();
});

$container->set(EventRepositoryInterface::class, function () {
    return new EventRepository();
});

$container->set(SeasonRepositoryInterface::class, function () {
    return new SeasonRepository();
});

$container->set(UserRepositoryInterface::class, function () {
    return new UserRepository();
});

// Services (External Adapters)
$container->set(EmailServiceInterface::class, function () {
    return new PhpMailerEmailService();
});

$container->set(EmailTemplateServiceInterface::class, function () {
    return new EmailTemplateService();
});

$container->set(CalendarServiceInterface::class, function () {
    return new ICalendarService();
});

$container->set(TimeServiceInterface::class, function ($c) {
    return new SystemTimeService($c->get(SeasonRepositoryInterface::class));
});

$container->set(TokenServiceInterface::class, function () use ($config) {
    return new JwtTokenService(
        $config['jwt']['secret'],
        $config['jwt']['expiration_minutes']
    );
});

$container->set(PasswordServiceInterface::class, function () {
    return new PhpPasswordService();
});

$container->set(TransactionServiceInterface::class, function () {
    return new DatabaseTransactionService();
});

// =======================
// Domain Layer
// =======================

$container->set(RankingService::class, function ($c) {
    return new RankingService(
        $c->get(TimeServiceInterface::class)
    );
});

$container->set(SelectionService::class, function () {
    return new SelectionService();
});

$container->set(AssignmentService::class, function () {
    return new AssignmentService();
});

// =======================
// Application Layer - Use Cases
// =======================

// Boat Use Cases
$container->set(\App\Application\UseCase\Boat\UpdateBoatAvailabilityUseCase::class, function ($c) {
    return new \App\Application\UseCase\Boat\UpdateBoatAvailabilityUseCase(
        $c->get(BoatRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(TimeServiceInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

// Crew Use Cases
$container->set(\App\Application\UseCase\Crew\UpdateCrewAvailabilityUseCase::class, function ($c) {
    return new \App\Application\UseCase\Crew\UpdateCrewAvailabilityUseCase(
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(TimeServiceInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Crew\GetCrewAvailabilityUseCase::class, function ($c) {
    return new \App\Application\UseCase\Crew\GetCrewAvailabilityUseCase(
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Crew\GetUserAssignmentsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Crew\GetUserAssignmentsUseCase(
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

// Event Use Cases
$container->set(\App\Application\UseCase\Event\GetAllEventsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Event\GetAllEventsUseCase(
        $c->get(EventRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Event\GetEventUseCase::class, function ($c) {
    return new \App\Application\UseCase\Event\GetEventUseCase(
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

// Flotilla Use Cases
$container->set(\App\Application\UseCase\Flotilla\GetAllFlotillasUseCase::class, function ($c) {
    return new \App\Application\UseCase\Flotilla\GetAllFlotillasUseCase(
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

// Season Use Cases
$container->set(\App\Application\UseCase\Season\ProcessSeasonUpdateUseCase::class, function ($c) {
    return new \App\Application\UseCase\Season\ProcessSeasonUpdateUseCase(
        $c->get(BoatRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class),
        $c->get(SelectionService::class),
        $c->get(AssignmentService::class),
        $c->get(RankingService::class),
        $c->get(TransactionServiceInterface::class)
    );
});

$container->set(\App\Application\UseCase\Season\GenerateFlotillaUseCase::class, function ($c) {
    return new \App\Application\UseCase\Season\GenerateFlotillaUseCase(
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Season\UpdateConfigUseCase::class, function ($c) {
    return new \App\Application\UseCase\Season\UpdateConfigUseCase(
        $c->get(SeasonRepositoryInterface::class)
    );
});

// Admin Use Cases
$container->set(\App\Application\UseCase\Admin\GetMatchingDataUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetMatchingDataUseCase(
        $c->get(BoatRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetParticipantEmailsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetParticipantEmailsUseCase(
        $c->get(BoatRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\SendCustomNotificationUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\SendCustomNotificationUseCase(
        $c->get(BoatRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(EmailServiceInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\SendNotificationsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\SendNotificationsUseCase(
        $c->get(EventRepositoryInterface::class),
        $c->get(SeasonRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(EmailServiceInterface::class),
        $c->get(EmailTemplateServiceInterface::class),
        $c->get(CalendarServiceInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetConfigUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetConfigUseCase(
        $c->get(SeasonRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetAllUsersUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetAllUsersUseCase(
        $c->get(UserRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\SetUserAdminUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\SetUserAdminUseCase(
        $c->get(UserRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetUserDetailUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetUserDetailUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetAllCrewsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetAllCrewsUseCase(
        $c->get(CrewRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\GetAllBoatsUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\GetAllBoatsUseCase(
        $c->get(BoatRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\UpdateCrewProfileUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\UpdateCrewProfileUseCase(
        $c->get(CrewRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\AddToCrewWhitelistUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\AddToCrewWhitelistUseCase(
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase(
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Admin\SetCrewCommitmentRankUseCase::class, function ($c) {
    return new \App\Application\UseCase\Admin\SetCrewCommitmentRankUseCase(
        $c->get(CrewRepositoryInterface::class)
    );
});

// Auth Use Cases
$container->set(\App\Application\UseCase\Auth\RegisterUseCase::class, function ($c) use ($config) {
    return new \App\Application\UseCase\Auth\RegisterUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class),
        $c->get(PasswordServiceInterface::class),
        $c->get(TokenServiceInterface::class),
        $c->get(RankingService::class),
        $c->get(EmailServiceInterface::class),
        $c->get(EmailTemplateServiceInterface::class),
        $config
    );
});

$container->set(\App\Application\UseCase\Auth\LoginUseCase::class, function ($c) {
    return new \App\Application\UseCase\Auth\LoginUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordServiceInterface::class),
        $c->get(TokenServiceInterface::class)
    );
});

$container->set(\App\Application\UseCase\Auth\GetSessionUseCase::class, function ($c) {
    return new \App\Application\UseCase\Auth\GetSessionUseCase(
        $c->get(UserRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\Auth\LogoutUseCase::class, function ($c) {
    return new \App\Application\UseCase\Auth\LogoutUseCase(
        $c->get(UserRepositoryInterface::class)
    );
});

// User Use Cases
$container->set(\App\Application\UseCase\User\GetUserProfileUseCase::class, function ($c) {
    return new \App\Application\UseCase\User\GetUserProfileUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class)
    );
});

$container->set(\App\Application\UseCase\User\UpdateUserProfileUseCase::class, function ($c) {
    return new \App\Application\UseCase\User\UpdateUserProfileUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class),
        $c->get(PasswordServiceInterface::class),
        $c->get(\App\Application\UseCase\User\GetUserProfileUseCase::class)
    );
});

$container->set(\App\Application\UseCase\User\AddProfileUseCase::class, function ($c) {
    return new \App\Application\UseCase\User\AddProfileUseCase(
        $c->get(UserRepositoryInterface::class),
        $c->get(CrewRepositoryInterface::class),
        $c->get(BoatRepositoryInterface::class),
        $c->get(RankingService::class),
        $c->get(\App\Application\UseCase\User\GetUserProfileUseCase::class)
    );
});

// =======================
// Presentation Layer - Controllers
// =======================

$container->set(\App\Presentation\Controller\EventController::class, function ($c) {
    return new \App\Presentation\Controller\EventController(
        $c->get(\App\Application\UseCase\Event\GetAllEventsUseCase::class),
        $c->get(\App\Application\UseCase\Event\GetEventUseCase::class),
        $c->get(\App\Application\UseCase\Flotilla\GetAllFlotillasUseCase::class),
        $c->get(TimeServiceInterface::class),
        $c->get(SeasonRepositoryInterface::class),
        $c->get(EventRepositoryInterface::class)
    );
});

$container->set(\App\Presentation\Controller\AvailabilityController::class, function ($c) {
    return new \App\Presentation\Controller\AvailabilityController(
        $c->get(\App\Application\UseCase\Boat\UpdateBoatAvailabilityUseCase::class),
        $c->get(\App\Application\UseCase\Crew\UpdateCrewAvailabilityUseCase::class),
        $c->get(\App\Application\UseCase\Crew\GetCrewAvailabilityUseCase::class),
        $c->get(\App\Application\UseCase\Season\ProcessSeasonUpdateUseCase::class)
    );
});

$container->set(\App\Presentation\Controller\AssignmentController::class, function ($c) {
    return new \App\Presentation\Controller\AssignmentController(
        $c->get(\App\Application\UseCase\Crew\GetUserAssignmentsUseCase::class)
    );
});

$container->set(\App\Presentation\Controller\AdminController::class, function ($c) {
    return new \App\Presentation\Controller\AdminController(
        $c->get(\App\Application\UseCase\Admin\GetMatchingDataUseCase::class),
        $c->get(\App\Application\UseCase\Admin\SendNotificationsUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetParticipantEmailsUseCase::class),
        $c->get(\App\Application\UseCase\Admin\SendCustomNotificationUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetConfigUseCase::class),
        $c->get(\App\Application\UseCase\Season\UpdateConfigUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetAllUsersUseCase::class),
        $c->get(\App\Application\UseCase\Admin\SetUserAdminUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetUserDetailUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetAllCrewsUseCase::class),
        $c->get(\App\Application\UseCase\Admin\GetAllBoatsUseCase::class),
        $c->get(\App\Application\UseCase\Admin\UpdateCrewProfileUseCase::class),
        $c->get(\App\Application\UseCase\Admin\AddToCrewWhitelistUseCase::class),
        $c->get(\App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase::class),
        $c->get(\App\Application\UseCase\Admin\SetCrewCommitmentRankUseCase::class)
    );
});

$container->set(\App\Presentation\Controller\AuthController::class, function ($c) {
    return new \App\Presentation\Controller\AuthController(
        $c->get(\App\Application\UseCase\Auth\RegisterUseCase::class),
        $c->get(\App\Application\UseCase\Auth\LoginUseCase::class),
        $c->get(\App\Application\UseCase\Auth\GetSessionUseCase::class),
        $c->get(\App\Application\UseCase\Auth\LogoutUseCase::class)
    );
});

$container->set(\App\Presentation\Controller\UserController::class, function ($c) {
    return new \App\Presentation\Controller\UserController(
        $c->get(\App\Application\UseCase\User\GetUserProfileUseCase::class),
        $c->get(\App\Application\UseCase\User\AddProfileUseCase::class),
        $c->get(\App\Application\UseCase\User\UpdateUserProfileUseCase::class)
    );
});

// =======================
// Presentation Layer - Middleware
// =======================

$container->set(\App\Presentation\Middleware\JwtAuthMiddleware::class, function ($c) {
    return new \App\Presentation\Middleware\JwtAuthMiddleware(
        $c->get(TokenServiceInterface::class)
    );
});

return $container;
