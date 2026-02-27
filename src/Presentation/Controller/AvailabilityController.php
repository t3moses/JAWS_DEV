<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\Boat\UpdateBoatAvailabilityUseCase;
use App\Application\UseCase\Crew\UpdateCrewAvailabilityUseCase;
use App\Application\UseCase\Crew\GetCrewAvailabilityUseCase;
use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use App\Application\DTO\Request\UpdateAvailabilityRequest;
use App\Application\Exception\ValidationException;
use App\Application\Exception\BlackoutWindowException;
use App\Application\Exception\BoatNotFoundException;
use App\Application\Exception\CrewNotFoundException;
use App\Application\Exception\EventNotFoundException;
use App\Presentation\Response\JsonResponse;

/**
 * Availability Controller
 *
 * Handles registration and availability updates (authenticated endpoints).
 */
class AvailabilityController
{
    public function __construct(
        private UpdateBoatAvailabilityUseCase $updateBoatAvailabilityUseCase,
        private UpdateCrewAvailabilityUseCase $updateCrewAvailabilityUseCase,
        private GetCrewAvailabilityUseCase $getCrewAvailabilityUseCase,
        private ProcessSeasonUpdateUseCase $processSeasonUpdateUseCase,
    ) {
    }

    /**
     * PATCH /api/users/me/availability
     *
     * Update availability for authenticated user.
     * Auto-detects if user is boat owner, crew member, or both (flex).
     * Updates all applicable entities.
     *
     * @param array $body Request body
     * @param array $auth Authentication data (user_id, email, account_type, is_admin)
     */
    public function updateAvailability(array $body, array $auth): JsonResponse
    {
        try {
            $request = new UpdateAvailabilityRequest(
                availabilities: $body['availabilities'] ?? []
            );

            $updated = [];
            $responseData = [];

            // Try to update boat (if user is boat owner)
            $boatResponse = null;
            try {
                $boatResponse = $this->updateBoatAvailabilityUseCase->execute(
                    $auth['user_id'],
                    $request
                );
                $updated[] = 'boat';
                $responseData['boat'] = $boatResponse->toArray();
            } catch (BoatNotFoundException $e) {
                // User is not a boat owner, continue
            }

            // Try to update crew (if user is crew member)
            $crewResponse = null;
            try {
                $crewResponse = $this->updateCrewAvailabilityUseCase->execute($auth['user_id'], $request);
                $updated[] = 'crew';
                $responseData['crew'] = $crewResponse->toArray();
            } catch (CrewNotFoundException $e) {
                // User is not a crew member, continue
            }

            // If neither boat nor crew was found, return 404
            if (empty($updated)) {
                return JsonResponse::notFound('User not found as boat owner or crew member');
            }

            // Trigger season update
            $this->processSeasonUpdateUseCase->execute();

            // Return unified response
            $responseData['updated'] = $updated;
            $responseData['message'] = 'Availability updated successfully';

            return JsonResponse::success($responseData);
        } catch (BlackoutWindowException $e) {
            return JsonResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400, $e->getErrors());
        } catch (EventNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/users/me/availability
     *
     * Get crew availability in simplified boolean format.
     *
     * @param array $auth Authentication data (user_id, email, account_type, is_admin)
     */
    public function getCrewAvailability(array $auth): JsonResponse
    {
        try {
            // Execute use case with user ID
            $response = $this->getCrewAvailabilityUseCase->execute($auth['user_id']);

            // Return success response
            return JsonResponse::success($response->toArray());
        } catch (CrewNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::serverError($e->getMessage());
        }
    }
}
