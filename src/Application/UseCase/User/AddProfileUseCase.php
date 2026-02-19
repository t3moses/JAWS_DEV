<?php

declare(strict_types=1);

namespace App\Application\UseCase\User;

use App\Application\DTO\Request\AddProfileRequest;
use App\Application\DTO\Response\ProfileResponse;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Service\RankingService;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\Enum\SkillLevel;

/**
 * Add Profile Use Case
 *
 * Adds crew or boat profile to existing user account.
 * Allows user to upgrade from crew-only to crew+boat or boat_owner-only to crew+boat_owner.
 */
class AddProfileUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private RankingService $rankingService,
        private GetUserProfileUseCase $getUserProfileUseCase,
    ) {
    }

    /**
     * Execute add profile
     *
     * @param int $userId User ID
     * @param AddProfileRequest $request Add profile request
     * @return ProfileResponse Updated profile with new crew or boat
     * @throws ValidationException If validation fails or profile already exists
     */
    public function execute(int $userId, AddProfileRequest $request): ProfileResponse
    {
        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Get user
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        // Add crew or boat profile based on request
        if ($request->profileType === 'crew') {
            $this->addCrewProfile($user->getId(), $request->crewProfile);
        } elseif ($request->profileType === 'boat') {
            $this->addBoatProfile($user->getId(), $request->boatProfile);
        }

        // Return updated profile
        return $this->getUserProfileUseCase->execute($userId);
    }

    /**
     * Add crew profile
     *
     * @param int $userId User ID
     * @param array $profile Crew profile data
     * @return void
     * @throws ValidationException If user already has crew profile
     */
    private function addCrewProfile(int $userId, array $profile): void
    {
        // Check if user already has crew profile
        $existingCrew = $this->crewRepository->findByUserId($userId);
        if ($existingCrew !== null) {
            throw new ValidationException(['crew_profile' => 'User already has a crew profile']);
        }

        // Create crew key
        $crewKey = CrewKey::fromName($profile['firstName'], $profile['lastName']);

        // Check if crew key already exists (different user with same name)
        $existingCrewByKey = $this->crewRepository->findByKey($crewKey);
        if ($existingCrewByKey !== null) {
            throw new ValidationException(['crewProfile' => 'A crew member with this name already exists']);
        }

        // Create crew entity
        $crew = new Crew(
            key: $crewKey,
            displayName: $profile['displayName'] ?? null,
            firstName: $profile['firstName'],
            lastName: $profile['lastName'],
            partnerKey: isset($profile['partnerKey']) ? new CrewKey($profile['partnerKey']) : null,
            mobile: $profile['mobile'] ?? null,
            socialPreference: $profile['socialPreference'] ?? 'No',
            membershipNumber: $profile['membershipNumber'] ?? null,
            skill: isset($profile['skill']) ? SkillLevel::from((int)$profile['skill']) : SkillLevel::NOVICE,
            experience: $profile['experience'] ?? null,
        );

        // Calculate initial rank
        $rank = $this->rankingService->calculateCrewRank($crew, []);

        // Set rank
        $crew->setRank($rank);

        // Link to user
        $crew->setUserId($userId);

        // Save crew
        $this->crewRepository->save($crew);
    }

    /**
     * Add boat profile
     *
     * @param int $userId User ID
     * @param array $profile Boat profile data
     * @return void
     * @throws ValidationException If user already has boat profile
     */
    private function addBoatProfile(int $userId, array $profile): void
    {
        // Check if user already has boat profile
        $existingBoat = $this->boatRepository->findByOwnerUserId($userId);
        if ($existingBoat !== null) {
            throw new ValidationException(['boat_profile' => 'User already has a boat profile']);
        }

        // If no displayName provided, generate key from owner's name; otherwise use displayName
        $displayName = $profile['displayName'] ?? null;
        if ($displayName !== null && trim($displayName) !== '') {
            $boatKey = BoatKey::fromBoatName($displayName);
        } else {
            // Generate key from owner's name if no boat name provided
            $keyName = trim($profile['ownerFirstName']) . trim($profile['ownerLastName']);
            $boatKey = BoatKey::fromBoatName($keyName);
        }

        // Check if boat key already exists (different user with same boat name)
        $existingBoatByKey = $this->boatRepository->findByKey($boatKey);
        if ($existingBoatByKey !== null) {
            throw new ValidationException(['boatProfile' => 'A boat with this name already exists']);
        }

        // Create boat entity
        $boat = new Boat(
            key: $boatKey,
            displayName: $displayName,
            ownerFirstName: $profile['ownerFirstName'],
            ownerLastName: $profile['ownerLastName'],
            ownerMobile: $profile['ownerMobile'] ?? null,
            minBerths: (int)$profile['minBerths'],
            maxBerths: (int)$profile['maxBerths'],
            assistanceRequired: $profile['assistanceRequired'] ?? 'No',
            socialPreference: $profile['socialPreference'] ?? 'No',
        );

        // Calculate initial rank
        $rank = $this->rankingService->calculateBoatRank($boat, []);

        // Set rank
        $boat->setRank($rank);

        // Link to user
        $boat->setOwnerUserId($userId);

        // Save boat
        $this->boatRepository->save($boat);
    }
}
