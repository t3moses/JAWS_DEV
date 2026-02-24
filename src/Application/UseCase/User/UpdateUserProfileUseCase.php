<?php

declare(strict_types=1);

namespace App\Application\UseCase\User;

use App\Application\DTO\Request\UpdateProfileRequest;
use App\Application\DTO\Response\ProfileResponse;
use App\Application\Exception\ValidationException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\PasswordServiceInterface;

/**
 * Update User Profile Use Case
 *
 * Updates user account information (email, password) and/or crew/boat profiles.
 */
class UpdateUserProfileUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private PasswordServiceInterface $passwordService,
        private GetUserProfileUseCase $getUserProfileUseCase,
    ) {
    }

    /**
     * Execute update user profile
     *
     * @param int $userId User ID
     * @param UpdateProfileRequest $request Update request
     * @return ProfileResponse Updated profile
     * @throws ValidationException If validation fails
     * @throws WeakPasswordException If password doesn't meet requirements
     */
    public function execute(int $userId, UpdateProfileRequest $request): ProfileResponse
    {
        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Check if request has any updates
        if (!$request->hasUpdates()) {
            throw new ValidationException(['error' => 'No updates provided']);
        }

        // Get user
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        // Update email if provided
        if ($request->email !== null && !empty($request->email)) {
            // Check if email is already taken by another user
            $existingUser = $this->userRepository->findByEmail($request->email);
            if ($existingUser !== null && $existingUser->getId() !== $userId) {
                throw new ValidationException(['email' => 'Email already exists']);
            }
            $user->setEmail($request->email);
        }

        // Update password if provided
        if ($request->password !== null && !empty($request->password)) {
            if (!$this->passwordService->meetsRequirements($request->password)) {
                throw new WeakPasswordException($this->passwordService->getRequirementsMessage());
            }
            $passwordHash = $this->passwordService->hash($request->password);
            $user->setPasswordHash($passwordHash);
        }

        // Save user updates
        $this->userRepository->save($user);

        // Update crew profile if provided
        if ($request->crewProfile !== null && !empty($request->crewProfile)) {
            $this->updateCrewProfile($userId, $request->crewProfile);
        }

        // Update boat profile if provided
        if ($request->boatProfile !== null && !empty($request->boatProfile)) {
            $this->updateBoatProfile($userId, $request->boatProfile);
        }

        // Return updated profile
        return $this->getUserProfileUseCase->execute($userId);
    }

    /**
     * Update crew profile
     *
     * @param int $userId User ID
     * @param array $profile Crew profile updates
     * @return void
     */
    private function updateCrewProfile(int $userId, array $profile): void
    {
        $crew = $this->crewRepository->findByUserId($userId);
        if ($crew === null) {
            throw new ValidationException(['crew_profile' => 'User does not have a crew profile']);
        }

        // Store original rank to preserve it (especially flexibility rank)
        $originalRank = $crew->getRank();

        // Update allowed fields
        if (isset($profile['firstName'])) {
            $crew->setFirstName($profile['firstName']);
        }
        if (isset($profile['lastName'])) {
            $crew->setLastName($profile['lastName']);
        }
        if (isset($profile['displayName'])) {
            $crew->setDisplayName($profile['displayName']);
        }
        if (isset($profile['mobile'])) {
            $crew->setMobile($profile['mobile']);
        }
        if (isset($profile['skill'])) {
            $crew->setSkill(\App\Domain\Enum\SkillLevel::from((int)$profile['skill']));
        }
        if (isset($profile['socialPreference'])) {
            $crew->setSocialPreference($profile['socialPreference']);
        }
        if (isset($profile['membershipNumber'])) {
            $crew->setMembershipNumber($profile['membershipNumber']);
        }
        // Restore original rank before saving to avoid overwriting flexibility
        $crew->setRank($originalRank);

        $this->crewRepository->save($crew);
    }

    /**
     * Update boat profile
     *
     * @param int $userId User ID
     * @param array $profile Boat profile updates
     * @return void
     */
    private function updateBoatProfile(int $userId, array $profile): void
    {
        $boat = $this->boatRepository->findByOwnerUserId($userId);
        if ($boat === null) {
            throw new ValidationException(['boat_profile' => 'User does not have a boat profile']);
        }

        // Store original rank to preserve it (especially flexibility rank)
        $originalRank = $boat->getRank();

        // Update allowed fields
        if (isset($profile['ownerFirstName'])) {
            $boat->setOwnerFirstName($profile['ownerFirstName']);
        }
        if (isset($profile['ownerLastName'])) {
            $boat->setOwnerLastName($profile['ownerLastName']);
        }
        if (isset($profile['displayName'])) {
            $boat->setDisplayName($profile['displayName']);
        }
        if (isset($profile['ownerMobile'])) {
            $boat->setOwnerMobile($profile['ownerMobile']);
        }
        if (isset($profile['minBerths'])) {
            $boat->setMinBerths((int)$profile['minBerths']);
        }
        if (isset($profile['maxBerths'])) {
            $boat->setMaxBerths((int)$profile['maxBerths']);
        }
        if (isset($profile['assistanceRequired'])) {
            $boat->setAssistanceRequired($profile['assistanceRequired']);
        }
        if (isset($profile['socialPreference'])) {
            $boat->setSocialPreference($profile['socialPreference']);
        }
        if (isset($profile['willingToCrew'])) {
            $boat->setRankDimension(
                \App\Domain\Enum\BoatRankDimension::FLEXIBILITY,
                ((bool)$profile['willingToCrew']) ? 0 : 1
            );
        }

        // Restore original rank to avoid overwriting pipeline-managed values,
        // but carry forward any willingToCrew change in the flexibility dimension
        $rankToRestore = $originalRank;
        if (isset($profile['willingToCrew'])) {
            $rankToRestore = $rankToRestore->withDimension(
                \App\Domain\Enum\BoatRankDimension::FLEXIBILITY,
                ((bool)$profile['willingToCrew']) ? 0 : 1
            );
        }
        $boat->setRank($rankToRestore);

        $this->boatRepository->save($boat);
    }
}
