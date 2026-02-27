<?php

declare(strict_types=1);

/**
 * Route Definitions
 *
 * Maps HTTP methods and paths to controller actions.
 * Uses simple pattern matching for route parameters.
 *
 * Route Format:
 * [
 *     'method' => 'GET|POST|PUT|PATCH|DELETE',
 *     'path' => '/api/endpoint',
 *     'controller' => ControllerClass::class,
 *     'action' => 'methodName',
 *     'auth' => true|false  // Whether authentication is required
 * ]
 */

use App\Presentation\Controller\EventController;
use App\Presentation\Controller\AvailabilityController;
use App\Presentation\Controller\AssignmentController;
use App\Presentation\Controller\AdminController;
use App\Presentation\Controller\AuthController;
use App\Presentation\Controller\UserController;

return [
    // =======================
    // Public Endpoints
    // =======================

    [
        'method' => 'GET',
        'path' => '/api/status',
        'controller' => EventController::class,
        'action' => 'getStatus',
        'auth' => false,
    ],

    [
        'method' => 'GET',
        'path' => '/api/events',
        'controller' => EventController::class,
        'action' => 'getAll',
        'auth' => false,
    ],

    [
        'method' => 'GET',
        'path' => '/api/events/{id}',
        'controller' => EventController::class,
        'action' => 'getOne',
        'auth' => false,
    ],

    [
        'method' => 'GET',
        'path' => '/api/flotillas',
        'controller' => EventController::class,
        'action' => 'getAllFlotillas',
        'auth' => false,
    ],

    // =======================
    // Authentication Endpoints
    // =======================

    [
        'method' => 'POST',
        'path' => '/api/auth/register',
        'controller' => AuthController::class,
        'action' => 'register',
        'auth' => false,
    ],

    [
        'method' => 'POST',
        'path' => '/api/auth/login',
        'controller' => AuthController::class,
        'action' => 'login',
        'auth' => false,
    ],

    [
        'method' => 'GET',
        'path' => '/api/auth/session',
        'controller' => AuthController::class,
        'action' => 'getSession',
        'auth' => true,
    ],

    [
        'method' => 'POST',
        'path' => '/api/auth/logout',
        'controller' => AuthController::class,
        'action' => 'logout',
        'auth' => true,
    ],

    // =======================
    // User Profile Endpoints
    // =======================

    [
        'method' => 'GET',
        'path' => '/api/users/me',
        'controller' => UserController::class,
        'action' => 'getProfile',
        'auth' => true,
    ],

    [
        'method' => 'POST',
        'path' => '/api/users/me',
        'controller' => UserController::class,
        'action' => 'addProfile',
        'auth' => true,
    ],

    [
        'method' => 'PATCH',
        'path' => '/api/users/me',
        'controller' => UserController::class,
        'action' => 'updateProfile',
        'auth' => true,
    ],

    // =======================
    // Authenticated Endpoints (Name-Based)
    // =======================

    // Update Availability (auto-detects boat owner, crew, or both)
    [
        'method' => 'PATCH',
        'path' => '/api/users/me/availability',
        'controller' => AvailabilityController::class,
        'action' => 'updateAvailability',
        'auth' => true,
    ],

    // Get Crew Availability
    [
        'method' => 'GET',
        'path' => '/api/users/me/availability',
        'controller' => AvailabilityController::class,
        'action' => 'getCrewAvailability',
        'auth' => true,
    ],

    // Get User Assignments
    [
        'method' => 'GET',
        'path' => '/api/assignments',
        'controller' => AssignmentController::class,
        'action' => 'getUserAssignments',
        'auth' => true,
    ],

    // =======================
    // Admin Endpoints
    // =======================

    // Get Matching Data for Event
    [
        'method' => 'GET',
        'path' => '/api/admin/matching/{eventId}',
        'controller' => AdminController::class,
        'action' => 'getMatchingData',
        'auth' => true,
    ],

    // Get Participant Emails for Event
    [
        'method' => 'GET',
        'path' => '/api/admin/participants/{eventId}',
        'controller' => AdminController::class,
        'action' => 'getParticipantEmails',
        'auth' => true,
    ],

    // Send Custom Notification (admin-composed BCC message)
    [
        'method' => 'POST',
        'path' => '/api/admin/notifications/{eventId}/custom',
        'controller' => AdminController::class,
        'action' => 'sendCustomNotification',
        'auth' => true,
    ],

    // Get Configuration
    [
        'method' => 'GET',
        'path' => '/api/admin/config',
        'controller' => AdminController::class,
        'action' => 'getConfig',
        'auth' => true,
    ],

    // Update Configuration
    [
        'method' => 'PATCH',
        'path' => '/api/admin/config',
        'controller' => AdminController::class,
        'action' => 'updateConfig',
        'auth' => true,
    ],

    // Get All Users
    [
        'method' => 'GET',
        'path' => '/api/admin/users',
        'controller' => AdminController::class,
        'action' => 'getUsers',
        'auth' => true,
    ],

    // Set User Admin Status
    [
        'method' => 'PATCH',
        'path' => '/api/admin/users/{userId}/admin',
        'controller' => AdminController::class,
        'action' => 'setUserAdmin',
        'auth' => true,
    ],

    // Get Single User Detail (with crew profile)
    [
        'method' => 'GET',
        'path' => '/api/admin/users/{userId}',
        'controller' => AdminController::class,
        'action' => 'getUser',
        'auth' => true,
    ],

    // Get All Crews (for partner picker)
    [
        'method' => 'GET',
        'path' => '/api/admin/crews',
        'controller' => AdminController::class,
        'action' => 'getAllCrews',
        'auth' => true,
    ],

    // Get All Boats (for whitelist picker)
    [
        'method' => 'GET',
        'path' => '/api/admin/boats',
        'controller' => AdminController::class,
        'action' => 'getAllBoats',
        'auth' => true,
    ],

    // Update Crew Profile (skill and/or partner)
    [
        'method' => 'PATCH',
        'path' => '/api/admin/crews/{crewKey}',
        'controller' => AdminController::class,
        'action' => 'updateCrewProfile',
        'auth' => true,
    ],

    // Add Boat to Crew Whitelist
    [
        'method' => 'POST',
        'path' => '/api/admin/crews/{crewKey}/whitelist/{boatKey}',
        'controller' => AdminController::class,
        'action' => 'addToWhitelist',
        'auth' => true,
    ],

    // Remove Boat from Crew Whitelist
    [
        'method' => 'DELETE',
        'path' => '/api/admin/crews/{crewKey}/whitelist/{boatKey}',
        'controller' => AdminController::class,
        'action' => 'removeFromWhitelist',
        'auth' => true,
    ],

    // Set Crew Commitment Rank (admin override)
    [
        'method' => 'PATCH',
        'path' => '/api/admin/crews/{crewKey}/commitment-rank',
        'controller' => AdminController::class,
        'action' => 'setCrewCommitmentRank',
        'auth' => true,
    ],
];
