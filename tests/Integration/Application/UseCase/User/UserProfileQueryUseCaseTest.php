<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\User;

use App\Application\UseCase\User\GetUserProfileUseCase;
use App\Application\DTO\Response\ProfileResponse;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Domain\Entity\User;
use App\Domain\Entity\Crew;
use App\Domain\Entity\Boat;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\BoatKey;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for User Profile Query UseCase operations
 *
 * Tests user profile retrieval including:
 * - Getting complete user profile
 * - Including crew profile data
 * - Including boat profile data
 * - Handling users with no profiles
 */
class UserProfileQueryUseCaseTest extends IntegrationTestCase
{
    private GetUserProfileUseCase $getUserProfileUseCase;
    private UserRepository $userRepository;
    private CrewRepository $crewRepository;
    private BoatRepository $boatRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = new UserRepository();
        $this->crewRepository = new CrewRepository();
        $this->boatRepository = new BoatRepository();

        $this->getUserProfileUseCase = new GetUserProfileUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository
        );
    }

    // ==================== GetUserProfileUseCase Tests ====================

    public function testGetUserProfileThrowsExceptionWhenUserNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->getUserProfileUseCase->execute(999999);
    }

    public function testGetUserProfileReturnsProfileResponse(): void
    {
        $user = new User('test@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertInstanceOf(ProfileResponse::class, $profile);
    }

    public function testGetUserProfileIncludesUserData(): void
    {
        $user = new User('test@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNotNull($profile->user);
        $this->assertEquals('test@example.com', $profile->user->email);
        $this->assertEquals('crew', $profile->user->accountType);
        $this->assertFalse($profile->user->isAdmin);
    }

    public function testGetUserProfileReturnsNullCrewProfileWhenNone(): void
    {
        $user = new User('nocrewprofile@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNull($profile->crewProfile);
    }

    public function testGetUserProfileIncludesCrewProfileWhenExists(): void
    {
        $user = new User('crewuser@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $crew = new Crew(
            key: CrewKey::fromName('John', 'Sailor'),
            displayName: 'John Sailor',
            firstName: 'John',
            lastName: 'Sailor',
            partnerKey: null,
            mobile: '1234567890',
            socialPreference: false,
            membershipNumber: 'M123',
            skill: SkillLevel::INTERMEDIATE,
            experience: null
        );
        $crew->setUserId($user->getId());
        $this->crewRepository->save($crew);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNotNull($profile->crewProfile);
        $this->assertEquals('John Sailor', $profile->crewProfile->displayName);
        $this->assertEquals('John', $profile->crewProfile->firstName);
        $this->assertEquals('Sailor', $profile->crewProfile->lastName);
        $this->assertEquals(SkillLevel::INTERMEDIATE->value, $profile->crewProfile->skill);
    }

    public function testGetUserProfileReturnsNullBoatProfileWhenNone(): void
    {
        $user = new User('noboatprofile@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($user);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNull($profile->boatProfile);
    }

    public function testGetUserProfileIncludesBoatProfileWhenExists(): void
    {
        $user = new User('boatowner@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($user);

        $boat = new Boat(
            key: BoatKey::fromString('My Boat'),
            displayName: 'My Boat',
            ownerFirstName: 'Boat',
            ownerLastName: 'Owner',
            ownerMobile: '555-1234',
            minBerths: 2,
            maxBerths: 4,
            assistanceRequired: false,
            socialPreference: false
        );
        $boat->setOwnerUserId($user->getId());
        $this->boatRepository->save($boat);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNotNull($profile->boatProfile);
        $this->assertEquals('My Boat', $profile->boatProfile->displayName);
        $this->assertEquals(2, $profile->boatProfile->minBerths);
        $this->assertEquals(4, $profile->boatProfile->maxBerths);
        $this->assertFalse($profile->boatProfile->assistanceRequired);
    }

    public function testGetUserProfileIncludesBothCrewAndBoatProfiles(): void
    {
        $user = new User('both@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        // Add crew profile
        $crew = new Crew(
            key: CrewKey::fromName('Jane', 'Doe'),
            displayName: 'Jane Doe',
            firstName: 'Jane',
            lastName: 'Doe',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: 'M456',
            skill: SkillLevel::ADVANCED,
            experience: null
        );
        $crew->setUserId($user->getId());
        $this->crewRepository->save($crew);

        // Add boat profile
        $boat = new Boat(
            key: BoatKey::fromString('Jane\'s Boat'),
            displayName: 'Jane\'s Boat',
            ownerFirstName: 'Jane',
            ownerLastName: 'Doe',
            ownerMobile: '555-5678',
            minBerths: 3,
            maxBerths: 5,
            assistanceRequired: true,
            socialPreference: false
        );
        $boat->setOwnerUserId($user->getId());
        $this->boatRepository->save($boat);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertNotNull($profile->crewProfile);
        $this->assertNotNull($profile->boatProfile);
        $this->assertEquals('Jane Doe', $profile->crewProfile->displayName);
        $this->assertEquals('Jane\'s Boat', $profile->boatProfile->displayName);
    }

    public function testGetUserProfileHandlesAdminUser(): void
    {
        $user = new User('admin@example.com', 'hash', 'crew', true);
        $this->userRepository->save($user);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertTrue($profile->user->isAdmin);
    }

    public function testGetUserProfileIncludesCrewMobile(): void
    {
        $user = new User('crewwithmobile@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $crew = new Crew(
            key: CrewKey::fromName('Bob', 'Smith'),
            displayName: 'Bob Smith',
            firstName: 'Bob',
            lastName: 'Smith',
            partnerKey: null,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: 'M789',
            skill: SkillLevel::NOVICE,
            experience: null
        );
        $crew->setUserId($user->getId());
        $this->crewRepository->save($crew);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertEquals('555-1234', $profile->crewProfile->mobile);
        $this->assertTrue($profile->crewProfile->socialPreference);
    }

    public function testGetUserProfileIncludesBoatAssistanceFlag(): void
    {
        $user = new User('boatneedshelp@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($user);

        $boat = new Boat(
            key: BoatKey::fromString('Assisted Boat'),
            displayName: 'Assisted Boat',
            ownerFirstName: 'Needs',
            ownerLastName: 'Help',
            ownerMobile: '555-9999',
            minBerths: 1,
            maxBerths: 2,
            assistanceRequired: true,
            socialPreference: false
        );
        $boat->setOwnerUserId($user->getId());
        $this->boatRepository->save($boat);

        $profile = $this->getUserProfileUseCase->execute($user->getId());

        $this->assertTrue($profile->boatProfile->assistanceRequired);
    }

    public function testGetUserProfileConvertsToArray(): void
    {
        $user = new User('arraytest@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user);

        $crew = new Crew(
            key: CrewKey::fromName('Test', 'User'),
            displayName: 'Test User',
            firstName: 'Test',
            lastName: 'User',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::INTERMEDIATE,
            experience: null
        );
        $crew->setUserId($user->getId());
        $this->crewRepository->save($crew);

        $profile = $this->getUserProfileUseCase->execute($user->getId());
        $array = $profile->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('user', $array);
        $this->assertArrayHasKey('crewProfile', $array);
        $this->assertArrayHasKey('boatProfile', $array);
        $this->assertIsArray($array['user']);
        $this->assertIsArray($array['crewProfile']);
        $this->assertNull($array['boatProfile']);
    }

    public function testGetUserProfileForMultipleUsers(): void
    {
        // Create multiple users with different profiles
        $user1 = new User('user1@example.com', 'hash', 'crew', false);
        $this->userRepository->save($user1);

        $user2 = new User('user2@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($user2);

        $crew1 = new Crew(
            key: CrewKey::fromName('User', 'One'),
            displayName: 'User One',
            firstName: 'User',
            lastName: 'One',
            partnerKey: null,
            mobile: null,
            socialPreference: false,
            membershipNumber: null,
            skill: SkillLevel::ADVANCED,
            experience: null
        );
        $crew1->setUserId($user1->getId());
        $this->crewRepository->save($crew1);

        $boat2 = new Boat(
            key: BoatKey::fromString('User Two Boat'),
            displayName: 'User Two Boat',
            ownerFirstName: 'User',
            ownerLastName: 'Two',
            ownerMobile: '555-2222',
            minBerths: 2,
            maxBerths: 3,
            assistanceRequired: false,
            socialPreference: false
        );
        $boat2->setOwnerUserId($user2->getId());
        $this->boatRepository->save($boat2);

        $profile1 = $this->getUserProfileUseCase->execute($user1->getId());
        $profile2 = $this->getUserProfileUseCase->execute($user2->getId());

        $this->assertNotNull($profile1->crewProfile);
        $this->assertNull($profile1->boatProfile);
        $this->assertNull($profile2->crewProfile);
        $this->assertNotNull($profile2->boatProfile);
    }

    public function testGetUserProfileIncludesAccountType(): void
    {
        $crewUser = new User('crew@example.com', 'hash', 'crew', false);
        $this->userRepository->save($crewUser);

        $boatUser = new User('boat@example.com', 'hash', 'boat_owner', false);
        $this->userRepository->save($boatUser);

        $crewProfile = $this->getUserProfileUseCase->execute($crewUser->getId());
        $boatProfile = $this->getUserProfileUseCase->execute($boatUser->getId());

        $this->assertEquals('crew', $crewProfile->user->accountType);
        $this->assertEquals('boat_owner', $boatProfile->user->accountType);
    }
}
