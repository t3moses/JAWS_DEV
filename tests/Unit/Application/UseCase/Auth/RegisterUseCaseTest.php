<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Auth;

use App\Application\DTO\Request\RegisterRequest;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\CalendarServiceInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use App\Application\Port\Service\TokenServiceInterface;
use App\Application\UseCase\Auth\RegisterUseCase;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Service\RankingService;
use App\Domain\ValueObject\Rank;
use App\Infrastructure\Persistence\SQLite\Connection;
use PHPUnit\Framework\TestCase;

class RegisterUseCaseTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private CrewRepositoryInterface $crewRepository;
    private BoatRepositoryInterface $boatRepository;
    private PasswordServiceInterface $passwordService;
    private TokenServiceInterface $tokenService;
    private RankingService $rankingService;
    private EmailServiceInterface $emailService;
    private EmailTemplateServiceInterface $emailTemplateService;
    private EventRepositoryInterface $eventRepository;
    private CalendarServiceInterface $calendarService;
    private array $config;
    private RegisterUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for all dependencies
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $this->boatRepository = $this->createMock(BoatRepositoryInterface::class);
        $this->passwordService = $this->createMock(PasswordServiceInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->rankingService = $this->createMock(RankingService::class);
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->emailTemplateService = $this->createMock(EmailTemplateServiceInterface::class);
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->calendarService = $this->createMock(CalendarServiceInterface::class);

        // Mock config array
        $this->config = [
            'email' => [
                'admin_notification_email' => 'test-admin@example.com',
            ],
        ];

        // Setup common mock behaviors
        $this->userRepository->method('emailExists')->willReturn(false);
        $this->passwordService->method('meetsRequirements')->willReturn(true);
        $this->passwordService->method('hash')->willReturn('$2y$10$hashedpassword');
        $this->tokenService->method('generate')->willReturn('mock.jwt.token');
        $this->tokenService->method('getExpirationMinutes')->willReturn(60);

        // Default: no display name clashes
        $this->crewRepository->method('displayNameExists')->willReturn(false);
        $this->boatRepository->method('displayNameExists')->willReturn(false);

        // Mock user save to set ID
        $this->userRepository->method('save')->willReturnCallback(function ($user) {
            // Set user ID after save
            $reflection = new \ReflectionClass($user);
            $property = $reflection->getProperty('id');
            $property->setValue($user, 1);
        });

        // Setup ranking service to return valid ranks
        $this->rankingService->method('calculateCrewRank')->willReturn(
            Rank::forCrew(commitment: 0, membership: 0, absence: 0)
        );
        $this->rankingService->method('calculateBoatRank')->willReturn(
            Rank::forBoat(flexibility: 1, absence: 0)
        );

        // Default: no future events (falls back to plain send)
        $this->eventRepository->method('findFutureEvents')->willReturn([]);
        $this->calendarService->method('generateSeasonCalendar')->willReturn('BEGIN:VCALENDAR...');
        $this->emailService->method('sendWithAttachment')->willReturn(true);

        // Default: Email service returns true (success)
        $this->emailService->method('send')->willReturn(true);

        // Mock email template service to return HTML strings with actual data
        $this->emailTemplateService->method('renderCrewRegistrationNotification')
            ->willReturnCallback(function ($user, $profile) {
                // Return HTML that includes the actual profile data for testing
                return sprintf(
                    '<html><body>Crew member registration: %s %s (%s), Display: %s, Skill: %s, Member: %s, Mobile: %s</body></html>',
                    $profile['firstName'] ?? '',
                    $profile['lastName'] ?? '',
                    $user->getEmail(),
                    $profile['displayName'] ?? '',
                    ($profile['skill'] ?? 0) === 2 ? 'Advanced' : (($profile['skill'] ?? 0) === 1 ? 'Intermediate' : 'Novice'),
                    $profile['membershipNumber'] ?? '',
                    $profile['mobile'] ?? ''
                );
            });
        $this->emailTemplateService->method('renderBoatOwnerRegistrationNotification')
            ->willReturnCallback(function ($user, $profile) {
                // Return HTML that includes the actual profile data for testing
                return sprintf(
                    '<html><body>Boat Owner Registration: %s %s (%s), Boat: %s, Berths: %d-%d, Mobile: %s, Assistance: %s</body></html>',
                    $profile['ownerFirstName'] ?? '',
                    $profile['ownerLastName'] ?? '',
                    $user->getEmail(),
                    $profile['displayName'] ?? '',
                    $profile['minBerths'] ?? 0,
                    $profile['maxBerths'] ?? 0,
                    $profile['ownerMobile'] ?? '',
                    ($profile['assistanceRequired'] ?? false) ? 'Yes' : 'No'
                );
            });

        $this->useCase = new RegisterUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository,
            $this->passwordService,
            $this->tokenService,
            $this->rankingService,
            $this->emailService,
            $this->emailTemplateService,
            $this->eventRepository,
            $this->calendarService,
            $this->config
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Rollback any active transactions to clean up for next test
        try {
            Connection::rollBack();
        } catch (\Exception $e) {
            // Ignore if no transaction is active
        }
    }

    public function testCrewDisplayNameGeneratedWhenNull(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'john.smith@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'John',
                'lastName' => 'Smith',
                // No displayName provided
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('JohnS', $capturedCrew->getDisplayName());
    }

    public function testCrewDisplayNameGeneratedWhenEmptyString(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'jane.doe@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'displayName' => '',  // Empty string
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('JaneD', $capturedCrew->getDisplayName());
    }

    public function testCrewDisplayNamePreservedWhenProvided(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'custom.user@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'Sarah',
                'lastName' => 'Johnson',
                'displayName' => 'Skipper Sarah',  // Custom displayName
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('Skipper Sarah', $capturedCrew->getDisplayName());
    }

    public function testCrewDisplayNameHandlesUnicodeCharacters(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'li.ming@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => '李',
                'lastName' => '明',
                // No displayName provided
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('李明', $capturedCrew->getDisplayName());
    }

    public function testBoatDisplayNameGeneratedWhenNull(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedBoat = null;
        $this->boatRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Boat $boat) use (&$capturedBoat) {
                $capturedBoat = $boat;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'bob.boat@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Bob',
                'ownerLastName' => 'Johnson',
                'ownerMobile' => '555-1234',
                'minBerths' => 2,
                'maxBerths' => 4,
                // No displayName provided
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedBoat);
        $this->assertEquals('BobJ', $capturedBoat->getDisplayName());
    }

    public function testBoatDisplayNameGeneratedWhenEmptyString(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedBoat = null;
        $this->boatRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Boat $boat) use (&$capturedBoat) {
                $capturedBoat = $boat;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'alice.boat@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Alice',
                'ownerLastName' => 'Williams',
                'ownerMobile' => '555-5678',
                'minBerths' => 3,
                'maxBerths' => 6,
                'displayName' => '   ',  // Whitespace only
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedBoat);
        $this->assertEquals('AliceW', $capturedBoat->getDisplayName());
    }

    public function testBoatDisplayNamePreservedWhenProvided(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedBoat = null;
        $this->boatRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Boat $boat) use (&$capturedBoat) {
                $capturedBoat = $boat;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'custom.boat@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Mike',
                'ownerLastName' => 'Davis',
                'ownerMobile' => '555-9012',
                'minBerths' => 2,
                'maxBerths' => 5,
                'displayName' => 'Sea Breeze',  // Custom boat name
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedBoat);
        $this->assertEquals('Sea Breeze', $capturedBoat->getDisplayName());
    }

    public function testCrewDisplayNameHandlesWhitespaceInFirstName(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'whitespace@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => '  Mary  ',  // Whitespace around name
                'lastName' => 'Brown',
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('MaryB', $capturedCrew->getDisplayName());
    }

    public function testBoatDisplayNameUsedForBoatKey(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedBoat = null;
        $this->boatRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Boat $boat) use (&$capturedBoat) {
                $capturedBoat = $boat;
                return true;
            }));

        $request = new RegisterRequest(
            email: 'boat.key@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Chris',
                'ownerLastName' => 'Taylor',
                'ownerMobile' => '555-3456',
                'minBerths' => 2,
                'maxBerths' => 4,
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert
        $this->assertNotNull($capturedBoat);
        $this->assertEquals('ChrisT', $capturedBoat->getDisplayName());
        // Verify the boat key is also based on the displayName (normalized: lowercase, spaces removed)
        $this->assertEquals('christ', $capturedBoat->getKey()->toString());
    }

    public function testSendsAdminNotificationForCrewRegistration(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedEmails = [];
        $this->emailService->method('send')
            ->willReturnCallback(function ($to, $subject, $body) use (&$capturedEmails) {
                $capturedEmails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return true;
            });

        $request = new RegisterRequest(
            email: 'john.doe@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'displayName' => 'JohnD',
                'skill' => 1,
            ]
        );

        // Act
        $response = $this->useCase->execute($request);

        // Assert response
        $this->assertNotNull($response);
        $this->assertEquals('mock.jwt.token', $response->token);

        // Assert admin notification was sent
        $adminEmails = array_filter($capturedEmails, fn($e) => $e['to'] === 'test-admin@example.com');
        $this->assertNotEmpty($adminEmails);
        $adminEmail = array_values($adminEmails)[0];
        $this->assertStringContainsString('New Crew Registration', $adminEmail['subject']);
        $this->assertStringContainsString('Crew member registration', $adminEmail['body']);
    }

    public function testSendsAdminNotificationForBoatOwnerRegistration(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedEmails = [];
        $this->emailService->method('send')
            ->willReturnCallback(function ($to, $subject, $body) use (&$capturedEmails) {
                $capturedEmails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return true;
            });

        $request = new RegisterRequest(
            email: 'captain@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Captain',
                'ownerLastName' => 'Hook',
                'displayName' => 'The Jolly Roger',
                'minBerths' => 2,
                'maxBerths' => 4,
            ]
        );

        // Act
        $response = $this->useCase->execute($request);

        // Assert response
        $this->assertNotNull($response);
        $this->assertEquals('mock.jwt.token', $response->token);

        // Assert admin notification was sent
        $adminEmails = array_filter($capturedEmails, fn($e) => $e['to'] === 'test-admin@example.com');
        $this->assertNotEmpty($adminEmails);
        $adminEmail = array_values($adminEmails)[0];
        $this->assertStringContainsString('New Boat Owner Registration', $adminEmail['subject']);
        $this->assertStringContainsString('Boat Owner Registration', $adminEmail['body']);
    }

    public function testRegistrationSucceedsWhenEmailFails(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        // Email service returns false (failure)
        $this->emailService->expects($this->any())
            ->method('send')
            ->willReturn(false);

        $request = new RegisterRequest(
            email: 'test@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'Test',
                'lastName' => 'User',
            ]
        );

        // Act
        $response = $this->useCase->execute($request);

        // Assert - Registration should still succeed
        $this->assertNotNull($response);
        $this->assertEquals('mock.jwt.token', $response->token);
    }

    public function testRegistrationSucceedsWhenEmailThrowsException(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        // Email service throws exception
        $this->emailService->expects($this->any())
            ->method('send')
            ->willThrowException(new \Exception('Email service unavailable'));

        $request = new RegisterRequest(
            email: 'exception@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'Exception',
                'lastName' => 'Test',
            ]
        );

        // Act
        $response = $this->useCase->execute($request);

        // Assert - Registration should still succeed despite email exception
        $this->assertNotNull($response);
        $this->assertEquals('mock.jwt.token', $response->token);
    }

    public function testEmailContainsCrewDetailsForCrewRegistration(): void
    {
        // Arrange
        $this->crewRepository->method('findByKey')->willReturn(null);

        $capturedEmails = [];
        $this->emailService->method('send')
            ->willReturnCallback(function ($to, $subject, $body) use (&$capturedEmails) {
                $capturedEmails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return true;
            });

        $request = new RegisterRequest(
            email: 'detailed@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'Detailed',
                'lastName' => 'Tester',
                'displayName' => 'D.Tester',
                'skill' => 2,
                'membershipNumber' => '12345',
                'mobile' => '555-1234',
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert - Verify admin email contains key crew details
        $adminEmails = array_filter($capturedEmails, fn($e) => $e['to'] === 'test-admin@example.com');
        $this->assertNotEmpty($adminEmails);
        $capturedEmailBody = array_values($adminEmails)[0]['body'];
        $this->assertStringContainsString('Detailed', $capturedEmailBody);
        $this->assertStringContainsString('Tester', $capturedEmailBody);
        $this->assertStringContainsString('D.Tester', $capturedEmailBody);
        $this->assertStringContainsString('Advanced', $capturedEmailBody); // skill level 2
        $this->assertStringContainsString('12345', $capturedEmailBody); // membership number
        $this->assertStringContainsString('555-1234', $capturedEmailBody); // mobile
        $this->assertStringContainsString('detailed@example.com', $capturedEmailBody); // email
    }

    public function testCrewDisplayNameSuffixedOnClash(): void
    {
        // Arrange: first call ("JohnD") clashes, second call ("JohnD2") is free
        $this->crewRepository = $this->createMock(CrewRepositoryInterface::class);
        $this->crewRepository->method('findByKey')->willReturn(null);
        $this->crewRepository->method('displayNameExists')
            ->willReturnCallback(fn($name) => $name === 'JohnD');  // 'JohnD' taken, 'JohnD2' free

        $capturedCrew = null;
        $this->crewRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Crew $crew) use (&$capturedCrew) {
                $capturedCrew = $crew;
                return true;
            }));

        // Rebuild use case with the new mock
        $this->useCase = new RegisterUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository,
            $this->passwordService,
            $this->tokenService,
            $this->rankingService,
            $this->emailService,
            $this->emailTemplateService,
            $this->eventRepository,
            $this->calendarService,
            $this->config
        );

        $request = new RegisterRequest(
            email: 'john.denver@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: [
                'firstName' => 'John',
                'lastName' => 'Denver',
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert: display name got the "2" suffix because "JohnD" was taken
        $this->assertNotNull($capturedCrew);
        $this->assertEquals('JohnD2', $capturedCrew->getDisplayName());
    }

    public function testBoatDisplayNameSuffixedOnClash(): void
    {
        // Arrange: "JohnD" is taken, "JohnD2" is free
        $this->boatRepository = $this->createMock(BoatRepositoryInterface::class);
        $this->boatRepository->method('findByKey')->willReturn(null);
        $this->boatRepository->method('displayNameExists')
            ->willReturnCallback(fn($name) => $name === 'JohnD');
        $this->boatRepository->method('findAll')->willReturn([]);

        $capturedBoat = null;
        $this->boatRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Boat $boat) use (&$capturedBoat) {
                $capturedBoat = $boat;
                return true;
            }));

        // Rebuild use case with the new mock
        $this->useCase = new RegisterUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository,
            $this->passwordService,
            $this->tokenService,
            $this->rankingService,
            $this->emailService,
            $this->emailTemplateService,
            $this->eventRepository,
            $this->calendarService,
            $this->config
        );

        $request = new RegisterRequest(
            email: 'john.davidson@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'John',
                'ownerLastName' => 'Davidson',
                'minBerths' => 2,
                'maxBerths' => 4,
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert: display name got the "2" suffix because "JohnD" was taken
        $this->assertNotNull($capturedBoat);
        $this->assertEquals('JohnD2', $capturedBoat->getDisplayName());
        $this->assertEquals('johnd2', $capturedBoat->getKey()->toString());
    }

    public function testWelcomeEmailIncludesICalAttachmentWhenFutureEventsExist(): void
    {
        // Recreate mocks that need non-default behaviour
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->eventRepository->method('findFutureEvents')->willReturn(['fri-may-30']);
        $this->eventRepository->method('findById')->willReturn([
            'event_id'    => 'fri-may-30',
            'event_date'  => '2026-05-30',
            'start_time'  => '12:45:00',
            'finish_time' => '17:00:00',
        ]);

        $this->calendarService = $this->createMock(CalendarServiceInterface::class);
        $this->calendarService->expects($this->once())
            ->method('generateSeasonCalendar')
            ->willReturn('BEGIN:VCALENDAR...');

        $capturedArgs = null;
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->emailService->method('send')->willReturn(true);
        $this->emailService->method('sendWithAttachment')
            ->willReturnCallback(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return true;
            });

        $this->crewRepository->method('findByKey')->willReturn(null);

        $this->useCase = new RegisterUseCase(
            $this->userRepository,
            $this->crewRepository,
            $this->boatRepository,
            $this->passwordService,
            $this->tokenService,
            $this->rankingService,
            $this->emailService,
            $this->emailTemplateService,
            $this->eventRepository,
            $this->calendarService,
            $this->config
        );

        $request = new RegisterRequest(
            email: 'new@example.com',
            password: 'SecurePass123!',
            accountType: 'crew',
            profile: ['firstName' => 'New', 'lastName' => 'User']
        );

        $this->useCase->execute($request);

        $this->assertNotNull($capturedArgs);
        $this->assertEquals('new@example.com', $capturedArgs[0]);
        $this->assertEquals('social-day-cruising.ics', $capturedArgs[4]);
        $this->assertEquals('text/calendar', $capturedArgs[5]);
    }

    public function testEmailContainsBoatDetailsForBoatOwnerRegistration(): void
    {
        // Arrange
        $this->boatRepository->method('findByKey')->willReturn(null);

        $capturedEmails = [];
        $this->emailService->method('send')
            ->willReturnCallback(function ($to, $subject, $body) use (&$capturedEmails) {
                $capturedEmails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return true;
            });

        $request = new RegisterRequest(
            email: 'boat@example.com',
            password: 'SecurePass123!',
            accountType: 'boat_owner',
            profile: [
                'ownerFirstName' => 'Boat',
                'ownerLastName' => 'Owner',
                'displayName' => 'SS Minnow',
                'minBerths' => 3,
                'maxBerths' => 5,
                'ownerMobile' => '555-5678',
                'assistanceRequired' => true,
                'socialPreference' => true,
            ]
        );

        // Act
        $this->useCase->execute($request);

        // Assert - Verify admin email contains key boat details
        $adminEmails = array_filter($capturedEmails, fn($e) => $e['to'] === 'test-admin@example.com');
        $this->assertNotEmpty($adminEmails);
        $capturedEmailBody = array_values($adminEmails)[0]['body'];
        $this->assertStringContainsString('Boat', $capturedEmailBody);
        $this->assertStringContainsString('Owner', $capturedEmailBody);
        $this->assertStringContainsString('SS Minnow', $capturedEmailBody);
        $this->assertStringContainsString('3-5', $capturedEmailBody); // berth capacity
        $this->assertStringContainsString('555-5678', $capturedEmailBody); // mobile
        $this->assertStringContainsString('Yes', $capturedEmailBody); // assistance required
        $this->assertStringContainsString('boat@example.com', $capturedEmailBody); // email
    }
}
