<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\Admin\GetMatchingDataUseCase;
use App\Application\UseCase\Admin\GetParticipantEmailsUseCase;
use App\Application\UseCase\Admin\SendCustomNotificationUseCase;
use App\Application\UseCase\Admin\GetConfigUseCase;
use App\Application\UseCase\Admin\GetAllUsersUseCase;
use App\Application\UseCase\Admin\SetUserAdminUseCase;
use App\Application\UseCase\Admin\SetUserStatusUseCase;
use App\Application\UseCase\Admin\GetUserDetailUseCase;
use App\Application\UseCase\Admin\GetAllCrewsUseCase;
use App\Application\UseCase\Admin\GetAllBoatsUseCase;
use App\Application\UseCase\Admin\UpdateCrewProfileUseCase;
use App\Application\UseCase\Admin\AddToCrewWhitelistUseCase;
use App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase;
use App\Application\UseCase\Admin\SetCrewCommitmentRankUseCase;
use App\Application\UseCase\Season\UpdateConfigUseCase;
use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use App\Application\DTO\Request\UpdateConfigRequest;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Application\Exception\ValidationException;
use App\Presentation\Response\JsonResponse;

/**
 * Admin Controller
 *
 * Handles administrative endpoints (authenticated admin access).
 */
class AdminController
{
    public function __construct(
        private GetMatchingDataUseCase $getMatchingDataUseCase,
        private GetParticipantEmailsUseCase $getParticipantEmailsUseCase,
        private SendCustomNotificationUseCase $sendCustomNotificationUseCase,
        private GetConfigUseCase $getConfigUseCase,
        private UpdateConfigUseCase $updateConfigUseCase,
        private ProcessSeasonUpdateUseCase $processSeasonUpdateUseCase,
        private GetAllUsersUseCase $getAllUsersUseCase,
        private SetUserAdminUseCase $setUserAdminUseCase,
        private SetUserStatusUseCase $setUserStatusUseCase,
        private GetUserDetailUseCase $getUserDetailUseCase,
        private GetAllCrewsUseCase $getAllCrewsUseCase,
        private GetAllBoatsUseCase $getAllBoatsUseCase,
        private UpdateCrewProfileUseCase $updateCrewProfileUseCase,
        private AddToCrewWhitelistUseCase $addToCrewWhitelistUseCase,
        private RemoveFromCrewWhitelistUseCase $removeFromCrewWhitelistUseCase,
        private SetCrewCommitmentRankUseCase $setCrewCommitmentRankUseCase,
    ) {
    }

    /**
     * Check if the current user is an admin
     *
     * @param array $auth Authentication context from JWT middleware
     * @return bool
     */
    private function isAdmin(array $auth): bool
    {
        return isset($auth['is_admin']) && $auth['is_admin'] === true;
    }

    /**
     * GET /api/admin/matching/{eventId}
     *
     * Returns matching data for an event (capacity analysis).
     *
     * @param array $params Route parameters
     * @param array $auth Authentication context
     */
    public function getMatchingData(array $params, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->getMatchingDataUseCase->execute($params['eventId']);

            return JsonResponse::success($result);
        } catch (EventNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/participants/{eventId}
     *
     * Returns registered participant emails grouped by role (boat owners, crew members).
     *
     * @param array $params Route parameters
     * @param array $auth Authentication context
     */
    public function getParticipantEmails(array $params, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result  = $this->getParticipantEmailsUseCase->execute($params['eventId']);

            return JsonResponse::success($result);
        } catch (EventNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * POST /api/admin/notifications/{eventId}/custom
     *
     * Sends an admin-composed message to selected participant groups via BCC.
     *
     * @param array $params Route parameters
     * @param array $body Request body
     * @param array $auth Authentication context
     */
    public function sendCustomNotification(array $params, array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $subject         = (string)($body['subject'] ?? '');
            $message         = (string)($body['message'] ?? '');
            $sendToBoatOwners = (bool)($body['send_to_boat_owners'] ?? false);
            $sendToCrew      = (bool)($body['send_to_crew'] ?? false);

            $result = $this->sendCustomNotificationUseCase->execute(
                $params['eventId'],
                $subject,
                $message,
                $sendToBoatOwners,
                $sendToCrew
            );

            return JsonResponse::success($result);
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (EventNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/config
     *
     * Returns current season configuration.
     *
     * @param array $auth Authentication context
     */
    public function getConfig(array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->getConfigUseCase->execute();

            return JsonResponse::success($result);
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/config
     *
     * Updates season configuration.
     *
     * @param array $body Request body
     * @param array $auth Authentication context
     */
    public function updateConfig(array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $request = new UpdateConfigRequest(
                source: $body['source'] ?? null,
                simulatedDate: $body['simulated_date'] ?? null,
                year: isset($body['year']) ? (int)$body['year'] : null,
                startTime: $body['start_time'] ?? null,
                finishTime: $body['finish_time'] ?? null,
                blackoutFrom: $body['blackout_from'] ?? null,
                blackoutTo: $body['blackout_to'] ?? null,
            );

            $result = $this->updateConfigUseCase->execute($request);

            $recalculation = [];
            try {
                $recalculation = $this->processSeasonUpdateUseCase->execute();
            } catch (\Exception $e) {
                $recalculation = ['error' => $e->getMessage()];
            }

            return JsonResponse::success(array_merge($result, ['recalculation' => $recalculation]));
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/users
     *
     * Returns a list of all registered users (no password hashes).
     *
     * @param array $auth Authentication context
     */
    public function getUsers(array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->getAllUsersUseCase->execute();

            return JsonResponse::success($result);
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/users/{userId}/admin
     *
     * Grants or revokes admin privileges for a user.
     *
     * @param array $params Route parameters (userId)
     * @param array $body   Request body (is_admin boolean)
     * @param array $auth   Authentication context
     */
    public function setUserAdmin(array $params, array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $targetUserId = (int)$params['userId'];
            $isAdmin = (bool)($body['is_admin'] ?? false);
            $requestingUserId = (int)$auth['user_id'];

            $result = $this->setUserAdminUseCase->execute($targetUserId, $isAdmin, $requestingUserId);

            return JsonResponse::success($result);
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\RuntimeException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/users/{userId}/status
     *
     * Suspends (disables) or reactivates a user account. Reversible.
     *
     * @param array $params Route parameters (userId)
     * @param array $body   Request body (disabled boolean)
     * @param array $auth   Authentication context
     */
    public function setUserStatus(array $params, array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        if (!array_key_exists('disabled', $body)) {
            return JsonResponse::error('disabled is required', 400);
        }

        try {
            $targetUserId     = (int)$params['userId'];
            $disabled         = (bool)$body['disabled'];
            $requestingUserId = (int)$auth['user_id'];

            $result = $this->setUserStatusUseCase->execute($targetUserId, $disabled, $requestingUserId);

            return JsonResponse::success($result);
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\RuntimeException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/users/{userId}
     *
     * Returns a single user's info together with their linked crew profile (if any).
     *
     * @param array $params Route parameters (userId)
     * @param array $auth   Authentication context
     */
    public function getUser(array $params, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $userId = (int)$params['userId'];
            $result = $this->getUserDetailUseCase->execute($userId);

            return JsonResponse::success($result);
        } catch (\RuntimeException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/crews
     *
     * Returns all crew members (for partner picker dropdowns).
     *
     * @param array $auth Authentication context
     */
    public function getAllCrews(array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->getAllCrewsUseCase->execute();

            return JsonResponse::success($result);
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/admin/boats
     *
     * Returns all boats (for whitelist picker dropdowns).
     *
     * @param array $auth Authentication context
     */
    public function getAllBoats(array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->getAllBoatsUseCase->execute();

            return JsonResponse::success($result);
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/crews/{crewKey}
     *
     * Updates skill and/or partner assignment for a crew member.
     *
     * @param array $params Route parameters (crewKey)
     * @param array $body   Request body (skill, partner_key)
     * @param array $auth   Authentication context
     */
    public function updateCrewProfile(array $params, array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $crewKey    = $params['crewKey'];
            $skill      = isset($body['skill']) ? (int)$body['skill'] : null;
            $partnerKey = array_key_exists('partner_key', $body) ? ($body['partner_key'] === '' ? null : $body['partner_key']) : null;
            $clearPartner = array_key_exists('partner_key', $body) && ($body['partner_key'] === null || $body['partner_key'] === '');

            $result = $this->updateCrewProfileUseCase->execute($crewKey, $skill, $partnerKey, $clearPartner);

            return JsonResponse::success($result);
        } catch (CrewNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * POST /api/admin/crews/{crewKey}/whitelist/{boatKey}
     *
     * Adds a boat to a crew member's whitelist.
     *
     * @param array $params Route parameters (crewKey, boatKey)
     * @param array $auth   Authentication context
     */
    public function addToWhitelist(array $params, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->addToCrewWhitelistUseCase->execute($params['crewKey'], $params['boatKey']);

            return JsonResponse::success($result);
        } catch (CrewNotFoundException | BoatNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * DELETE /api/admin/crews/{crewKey}/whitelist/{boatKey}
     *
     * Removes a boat from a crew member's whitelist.
     *
     * @param array $params Route parameters (crewKey, boatKey)
     * @param array $auth   Authentication context
     */
    public function removeFromWhitelist(array $params, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $result = $this->removeFromCrewWhitelistUseCase->execute($params['crewKey'], $params['boatKey']);

            return JsonResponse::success($result);
        } catch (CrewNotFoundException | BoatNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/crews/{crewKey}/commitment-rank
     *
     * Sets the commitment rank for a crew member (admin override).
     *
     * Valid values: 0 (unavailable), 1 (penalty), 2 (normal), 3 (assigned)
     *
     * @param array $params Route parameters (crewKey)
     * @param array $body   Request body (commitment_rank)
     * @param array $auth   Authentication context
     */
    public function setCrewCommitmentRank(array $params, array $body, array $auth): JsonResponse
    {
        if (!$this->isAdmin($auth)) {
            return JsonResponse::error('Admin privileges required', 403);
        }

        try {
            $crewKey = $params['crewKey'];
            $rank = isset($body['commitment_rank']) ? (int)$body['commitment_rank'] : null;

            if ($rank === null) {
                return JsonResponse::error('commitment_rank is required', 400);
            }

            $result = $this->setCrewCommitmentRankUseCase->execute($crewKey, $rank);

            return JsonResponse::success($result);
        } catch (CrewNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }
}
