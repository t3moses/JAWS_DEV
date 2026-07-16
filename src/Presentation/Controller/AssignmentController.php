<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\Request\FlagAssignedCrewRequest;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\ValidationException;
use App\Application\UseCase\Boat\FlagAssignedCrewUseCase;
use App\Application\UseCase\Crew\GetUserAssignmentsUseCase;
use App\Presentation\Response\JsonResponse;

/**
 * Assignment Controller
 *
 * Handles assignment-related endpoints (authenticated access).
 */
class AssignmentController
{
    public function __construct(
        private GetUserAssignmentsUseCase $getUserAssignmentsUseCase,
        private FlagAssignedCrewUseCase $flagAssignedCrewUseCase,
    ) {
    }

    /**
     * GET /api/assignments
     *
     * Returns user's assignments across all events.
     *
     * @param array $auth Authentication data (user_id, email, account_type, is_admin)
     */
    public function getUserAssignments(array $auth): JsonResponse
    {
        try {
            // Execute use case with user ID
            $assignments = $this->getUserAssignmentsUseCase->execute($auth['user_id']);

            return JsonResponse::success([
                'assignments' => array_map(fn($a) => $a->toArray(), $assignments),
            ]);
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * POST /api/assignments/crew-flags
     *
     * Lets a boat owner flag crew members assigned to their boat, decrementing
     * each flagged crew's commitment rank by the number of times flagged.
     *
     * @param array $body Request body (flags: array of {eventId, crewKey})
     * @param array $auth Authentication data (user_id, email, account_type, is_admin)
     */
    public function flagCrew(array $body, array $auth): JsonResponse
    {
        try {
            $request = FlagAssignedCrewRequest::fromArray($body);
            $errors = $request->validate();
            if (!empty($errors)) {
                throw new ValidationException($errors);
            }

            $results = $this->flagAssignedCrewUseCase->execute($auth['user_id'], $request->flags);

            return JsonResponse::success(['flagged' => $results]);
        } catch (BoatNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }
}
