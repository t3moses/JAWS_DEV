<?php

declare(strict_types=1);

namespace Tests\Integration\Application\UseCase\Auth;

use App\Application\UseCase\Auth\RegisterUseCase;
use App\Application\DTO\Request\RegisterRequest;
use App\Application\Exception\UserAlreadyExistsException;
use App\Application\Exception\ValidationException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\Service\EmailServiceInterface;
use App\Infrastructure\Persistence\SQLite\UserRepository;
use App\Infrastructure\Persistence\SQLite\CrewRepository;
use App\Infrastructure\Persistence\SQLite\BoatRepository;
use App\Infrastructure\Service\PhpPasswordService;
use App\Infrastructure\Service\JwtTokenService;
use App\Domain\Entity\User;
use App\Domain\Enum\SkillLevel;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for RegisterUseCase
 *
 * Tests user registration for both crew and boat_owner accounts.
 */
class RegisterUseCaseTest extends IntegrationTestCase
{
    private RegisterUseCase $useCase;
    private UserRepository $userRepository;
    private CrewRepository $crewRepository;
    private BoatRepository $boatRepository;
    private PhpPasswordService $passwordService;
    private EmailServiceInterface $emailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = new UserRepository();
        $this->crewRepository = new CrewRepository();
        $this->boatRepository = new BoatRepository();
        $this->passwordService = new PhpPasswordService();
        $tokenService = new JwtTokenService();

        // We need RankingService for the usecase
        $rankingService = new \App\Domain\Service\RankingService();

        // Mock EmailService to avoid sending real emails during tests
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->emailService->method('send')->willReturn(true);

        // Mock EmailTemplateService
        $emailTemplateService = $this->createMock(\App\Application\Port\Service\EmailTemplateServiceInterface::class);
        $emailTemplateService->method('renderCrewRegistrationNotification')
            ->willReturn('<html><body>Crew Registration Email</body></html>');
        $emailTemplateService->method('renderBoatOwnerRegistrationNotification')
            ->willReturn('<html><body>Boat Owner Registration Email</body></html>');

        // Mock config array
        $config = [
            'email' => [
                'admin_notification_email' => 'test-admin@example.com',
            ],
        ];

        $this->useCase = new RegisterUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository,
            $this->passwordService,
            $tokenService,
            $rankingService,
            $this->emailService,
            $emailTemplateService,
            $config
        );
    }

    public function testRegisterCrewWithValidDataReturnsAuthResponse(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'newcrew@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Sailor',
                'skill' => SkillLevel::INTERMEDIATE->value,
            ]
        ]);

        $response = $this->useCase->execute($request);

        $this->assertNotNull($response->token);
        $this->assertNotEmpty($response->token);
        $this->assertEquals('newcrew@example.com', $response->user->email);
        $this->assertEquals('crew', $response->user->accountType);
        $this->assertFalse($response->user->isAdmin);
    }

    public function testRegisterBoatOwnerWithValidDataReturnsAuthResponse(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'newboat@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Mary',
                'ownerLastName' => 'Captain',
                'minBerths' => 4,
                'maxBerths' => 8,
                'ownerMobile' => '555-0000',
            ]
        ]);

        $response = $this->useCase->execute($request);

        $this->assertNotNull($response->token);
        $this->assertEquals('newboat@example.com', $response->user->email);
        $this->assertEquals('boat_owner', $response->user->accountType);
    }

    public function testRegisterCrewCreatesCrewProfile(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'crew.profile@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Alice',
                'lastName' => 'Wonder',
                'skill' => SkillLevel::ADVANCED->value,
                'mobile' => '555-1234',
            ]
        ]);

        $this->useCase->execute($request);

        // Verify crew was created
        $crew = $this->crewRepository->findByName('Alice', 'Wonder');
        $this->assertNotNull($crew);
        $this->assertEquals('Alice', $crew->getFirstName());
        $this->assertEquals('Wonder', $crew->getLastName());
        $this->assertEquals(SkillLevel::ADVANCED, $crew->getSkill());
        $this->assertEquals('555-1234', $crew->getMobile());
    }

    public function testRegisterBoatOwnerCreatesBoatProfile(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'boat.profile@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Tom',
                'ownerLastName' => 'Ships',
                'minBerths' => 2,
                'maxBerths' => 6,
                'ownerMobile' => '555-5678',
            ]
        ]);

        $this->useCase->execute($request);

        // Verify boat was created
        $boat = $this->boatRepository->findByOwnerName('Tom', 'Ships');
        $this->assertNotNull($boat);
        $this->assertEquals('Tom', $boat->getOwnerFirstName());
        $this->assertEquals('Ships', $boat->getOwnerLastName());
        $this->assertEquals(2, $boat->getMinBerths());
        $this->assertEquals(6, $boat->getMaxBerths());
        $this->assertEquals('555-5678', $boat->getOwnerMobile());
    }

    public function testRegisterWithDuplicateEmailThrowsException(): void
    {
        // Create first user
        $user = new User(
            email: 'duplicate@example.com',
            passwordHash: $this->passwordService->hash('Password123'),
            accountType: 'crew',
            isAdmin: false
        );
        $this->userRepository->save($user);

        // Attempt to register with same email
        $request = RegisterRequest::fromArray([
            'email' => 'duplicate@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Sailor',
            ]
        ]);

        $this->expectException(UserAlreadyExistsException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterWithWeakPasswordThrowsException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'weak@example.com',
            'password' => 'short',  // Too short
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Sailor',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterWithMissingEmailThrowsValidationException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => '',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Sailor',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterWithInvalidAccountTypeThrowsValidationException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'invalid@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'invalid_type',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Sailor',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterCrewWithMissingFirstNameThrowsValidationException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'missing@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => '',
                'lastName' => 'Sailor',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterBoatWithMissingOwnerNameThrowsValidationException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'missingowner@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => '',
                'ownerLastName' => 'Captain',
                'minBerths' => 4,
                'maxBerths' => 8,
                'ownerMobile' => '555-0003',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisterBoatWithInvalidBerthsThrowsValidationException(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'invalidberths@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Tom',
                'ownerLastName' => 'Ships',
                'minBerths' => 8,
                'maxBerths' => 4,  // Max less than min
                'ownerMobile' => '555-0004',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request);
    }

    public function testRegisteredUserCanLogin(): void
    {
        // Register
        $registerRequest = RegisterRequest::fromArray([
            'email' => 'canlogin@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Login',
                'lastName' => 'Test',
            ]
        ]);

        $this->useCase->execute($registerRequest);

        // Verify user was created and can be found
        $user = $this->userRepository->findByEmail('canlogin@example.com');
        $this->assertNotNull($user);
        $this->assertTrue($this->passwordService->verify('SecurePassword123', $user->getPasswordHash()));
    }

    public function testRegisterCrewWithAllOptionalFields(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'completecrew@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Complete',
                'lastName' => 'Sailor',
                'displayName' => 'Complete the Sailor',
                'skill' => SkillLevel::ADVANCED->value,
                'mobile' => '555-9999',
                'membership Number' => 'MEM-12345',
                'socialPreference' => true,
                'experience' => '10 years sailing',
            ]
        ]);

        $response = $this->useCase->execute($request);

        $this->assertNotNull($response->token);
        $crew = $this->crewRepository->findByName('Complete', 'Sailor');
        $this->assertNotNull($crew);
        $this->assertEquals('555-9999', $crew->getMobile());
        $this->assertTrue($crew->hasSocialPreference());
    }

    public function testRegisterBoatWithAllOptionalFields(): void
    {
        $request = RegisterRequest::fromArray([
            'email' => 'completeboat@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Complete',
                'ownerLastName' => 'Owner',
                'displayName' => 'Complete\'s Boat',
                'minBerths' => 2,
                'maxBerths' => 8,
                'ownerMobile' => '555-8888',
                'assistanceRequired' => true,
                'socialPreference' => false,
            ]
        ]);

        $response = $this->useCase->execute($request);

        $this->assertNotNull($response->token);
        $boat = $this->boatRepository->findByOwnerName('Complete', 'Owner');
        $this->assertNotNull($boat);
        $this->assertEquals('555-8888', $boat->getOwnerMobile());
        $this->assertTrue($boat->requiresAssistance());
    }

    public function testRegisterCrewWithDuplicateNameThrowsValidationException(): void
    {
        // Register first crew
        $request1 = RegisterRequest::fromArray([
            'email' => 'first@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Duplicate',
                'lastName' => 'Name',
            ]
        ]);
        $this->useCase->execute($request1);

        // Try to register second crew with same name
        $request2 = RegisterRequest::fromArray([
            'email' => 'second@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Duplicate',
                'lastName' => 'Name',
            ]
        ]);

        $this->expectException(ValidationException::class);

        $this->useCase->execute($request2);
    }

    public function testRegisterBoatWithSameLastInitialGetsUniqueDisplayName(): void
    {
        // Register first boat owner: John Doe → "JohnD"
        $request1 = RegisterRequest::fromArray([
            'email' => 'john.doe@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'John',
                'ownerLastName' => 'Doe',
                'minBerths' => 2,
                'maxBerths' => 6,
                'ownerMobile' => '555-0001',
            ]
        ]);
        $this->useCase->execute($request1);

        // Register second boat owner with same first-name + last-initial: John Davidson → "JohnD2"
        $request2 = RegisterRequest::fromArray([
            'email' => 'john.davidson@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'John',
                'ownerLastName' => 'Davidson',
                'minBerths' => 4,
                'maxBerths' => 8,
                'ownerMobile' => '555-0002',
            ]
        ]);
        $this->useCase->execute($request2);

        $boat1 = $this->boatRepository->findByOwnerName('John', 'Doe');
        $boat2 = $this->boatRepository->findByOwnerName('John', 'Davidson');

        $this->assertNotNull($boat1);
        $this->assertNotNull($boat2);
        $this->assertEquals('JohnD', $boat1->getDisplayName());
        $this->assertEquals('JohnD2', $boat2->getDisplayName());
        // Keys are derived from display names
        $this->assertEquals('johnd', $boat1->getKey()->toString());
        $this->assertEquals('johnd2', $boat2->getKey()->toString());
    }

    public function testRegisterBoatWithThirdClashGetsIncrementedSuffix(): void
    {
        // Three owners all generating "JohnD"
        foreach (['Doe', 'Davidson', 'Denver'] as $i => $lastName) {
            $request = RegisterRequest::fromArray([
                'email' => "john.{$lastName}@example.com",
                'password' => 'SecurePassword123',
                'accountType' => 'boat_owner',
                'profile' => [
                    'ownerFirstName' => 'John',
                    'ownerLastName' => $lastName,
                    'minBerths' => 2,
                    'maxBerths' => 4,
                    'ownerMobile' => "555-000{$i}",
                ]
            ]);
            $this->useCase->execute($request);
        }

        $this->assertEquals('JohnD', $this->boatRepository->findByOwnerName('John', 'Doe')->getDisplayName());
        $this->assertEquals('JohnD2', $this->boatRepository->findByOwnerName('John', 'Davidson')->getDisplayName());
        $this->assertEquals('JohnD3', $this->boatRepository->findByOwnerName('John', 'Denver')->getDisplayName());
    }

    public function testRegisterCrewWithSameLastInitialGetsUniqueDisplayName(): void
    {
        // Register first crew: John Doe → "JohnD"
        $request1 = RegisterRequest::fromArray([
            'email' => 'john.doe@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
            ]
        ]);
        $this->useCase->execute($request1);

        // Register second crew with same first-name + last-initial: John Denver → "JohnD2"
        $request2 = RegisterRequest::fromArray([
            'email' => 'john.denver@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'John',
                'lastName' => 'Denver',
            ]
        ]);
        $this->useCase->execute($request2);

        $crew1 = $this->crewRepository->findByName('John', 'Doe');
        $crew2 = $this->crewRepository->findByName('John', 'Denver');

        $this->assertNotNull($crew1);
        $this->assertNotNull($crew2);
        $this->assertEquals('JohnD', $crew1->getDisplayName());
        $this->assertEquals('JohnD2', $crew2->getDisplayName());
    }

    public function testRegisterCrewPopulatesWhitelistWithAllBoats(): void
    {
        // Create some boats first
        $boat1Request = RegisterRequest::fromArray([
            'email' => 'boat1@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Alice',
                'ownerLastName' => 'Anderson',
                'minBerths' => 2,
                'maxBerths' => 4,
                'ownerMobile' => '555-0001',
            ]
        ]);
        $this->useCase->execute($boat1Request);

        $boat2Request = RegisterRequest::fromArray([
            'email' => 'boat2@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Bob',
                'ownerLastName' => 'Builder',
                'minBerths' => 4,
                'maxBerths' => 6,
                'ownerMobile' => '555-0002',
            ]
        ]);
        $this->useCase->execute($boat2Request);

        $boat3Request = RegisterRequest::fromArray([
            'email' => 'boat3@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'Carol',
                'ownerLastName' => 'Captain',
                'minBerths' => 2,
                'maxBerths' => 6,
                'ownerMobile' => '555-0003',
            ]
        ]);
        $this->useCase->execute($boat3Request);

        // Now register a crew member
        $crewRequest = RegisterRequest::fromArray([
            'email' => 'newcrew@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'New',
                'lastName' => 'Crew',
                'skill' => SkillLevel::NOVICE->value,
            ]
        ]);
        $this->useCase->execute($crewRequest);

        // Verify crew's whitelist was populated with all boats
        $crew = $this->crewRepository->findByName('New', 'Crew');
        $this->assertNotNull($crew);

        $whitelist = $crew->getWhitelist();
        $this->assertCount(3, $whitelist, 'Whitelist should contain all 3 boats');

        // Verify each boat is in the whitelist
        $boat1 = $this->boatRepository->findByOwnerName('Alice', 'Anderson');
        $boat2 = $this->boatRepository->findByOwnerName('Bob', 'Builder');
        $boat3 = $this->boatRepository->findByOwnerName('Carol', 'Captain');

        $this->assertContains($boat1->getKey()->toString(), $whitelist);
        $this->assertContains($boat2->getKey()->toString(), $whitelist);
        $this->assertContains($boat3->getKey()->toString(), $whitelist);
    }

    public function testRegisterCrewWithNoBoatsHasEmptyWhitelist(): void
    {
        // Register a crew member when no boats exist
        $crewRequest = RegisterRequest::fromArray([
            'email' => 'earlybird@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Early',
                'lastName' => 'Bird',
                'skill' => SkillLevel::NOVICE->value,
            ]
        ]);
        $this->useCase->execute($crewRequest);

        // Verify crew's whitelist is empty
        $crew = $this->crewRepository->findByName('Early', 'Bird');
        $this->assertNotNull($crew);

        $whitelist = $crew->getWhitelist();
        $this->assertIsArray($whitelist);
        $this->assertCount(0, $whitelist, 'Whitelist should be empty when no boats exist');
    }

    public function testRegisterBoatAddsToAllExistingCrewWhitelists(): void
    {
        // First, register several crew members
        $crew1Request = RegisterRequest::fromArray([
            'email' => 'crew1@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Alice',
                'lastName' => 'Crew',
                'skill' => SkillLevel::INTERMEDIATE->value,
            ]
        ]);
        $this->useCase->execute($crew1Request);

        $crew2Request = RegisterRequest::fromArray([
            'email' => 'crew2@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Bob',
                'lastName' => 'Sailor',
                'skill' => SkillLevel::ADVANCED->value,
            ]
        ]);
        $this->useCase->execute($crew2Request);

        $crew3Request = RegisterRequest::fromArray([
            'email' => 'crew3@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'crew',
            'profile' => [
                'firstName' => 'Carol',
                'lastName' => 'Mate',
                'skill' => SkillLevel::NOVICE->value,
            ]
        ]);
        $this->useCase->execute($crew3Request);

        // Verify crews have empty whitelists initially
        $crew1 = $this->crewRepository->findByName('Alice', 'Crew');
        $crew2 = $this->crewRepository->findByName('Bob', 'Sailor');
        $crew3 = $this->crewRepository->findByName('Carol', 'Mate');

        $this->assertNotNull($crew1);
        $this->assertNotNull($crew2);
        $this->assertNotNull($crew3);

        $this->assertCount(0, $crew1->getWhitelist());
        $this->assertCount(0, $crew2->getWhitelist());
        $this->assertCount(0, $crew3->getWhitelist());

        // Now register a new boat
        $boatRequest = RegisterRequest::fromArray([
            'email' => 'newboat@example.com',
            'password' => 'SecurePassword123',
            'accountType' => 'boat_owner',
            'profile' => [
                'ownerFirstName' => 'David',
                'ownerLastName' => 'Captain',
                'assistanceRequired' => 'no',
                'socialPreference' => 'yes',
                'minBerths' => 2,
                'maxBerths' => 6,
                'ownerMobile' => '555-0100',
            ]
        ]);
        $this->useCase->execute($boatRequest);

        // Fetch the newly registered boat
        $boat = $this->boatRepository->findByOwnerName('David', 'Captain');
        $this->assertNotNull($boat);

        // Re-fetch all crews to get updated whitelists
        $crew1 = $this->crewRepository->findByName('Alice', 'Crew');
        $crew2 = $this->crewRepository->findByName('Bob', 'Sailor');
        $crew3 = $this->crewRepository->findByName('Carol', 'Mate');

        // Verify all crew whitelists now contain the new boat
        $whitelist1 = $crew1->getWhitelist();
        $whitelist2 = $crew2->getWhitelist();
        $whitelist3 = $crew3->getWhitelist();

        $this->assertCount(1, $whitelist1, 'Crew 1 whitelist should contain the new boat');
        $this->assertCount(1, $whitelist2, 'Crew 2 whitelist should contain the new boat');
        $this->assertCount(1, $whitelist3, 'Crew 3 whitelist should contain the new boat');

        $boatKeyString = $boat->getKey()->toString();
        $this->assertContains($boatKeyString, $whitelist1);
        $this->assertContains($boatKeyString, $whitelist2);
        $this->assertContains($boatKeyString, $whitelist3);
    }
}
