<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\DTO\Request\RegisterRequest;
use App\Application\DTO\Response\AuthResponse;
use App\Application\DTO\Response\UserResponse;
use App\Application\Exception\UserAlreadyExistsException;
use App\Application\Exception\ValidationException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\Repository\BoatRepositoryInterface;
use App\Application\Port\Repository\CrewRepositoryInterface;
use App\Application\Port\Repository\EventRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\CalendarServiceInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\Port\Service\PasswordServiceInterface;
use App\Application\Port\Service\TokenServiceInterface;
use App\Application\Port\Service\TransactionServiceInterface;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\Entity\User;
use App\Domain\Service\RankingService;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\SkillLevel;

/**
 * Register Use Case
 *
 * Handles user registration with crew or boat_owner account type.
 * Creates user account and associated crew or boat profile in a single transaction.
 */
class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CrewRepositoryInterface $crewRepository,
        private BoatRepositoryInterface $boatRepository,
        private PasswordServiceInterface $passwordService,
        private TokenServiceInterface $tokenService,
        private RankingService $rankingService,
        private EmailServiceInterface $emailService,
        private EmailTemplateServiceInterface $emailTemplateService,
        private EventRepositoryInterface $eventRepository,
        private CalendarServiceInterface $calendarService,
        private array $config,
        private TransactionServiceInterface $transactionService,
    ) {
    }

    /**
     * Execute registration
     *
     * @param RegisterRequest $request Registration request
     * @return AuthResponse Authentication response with token
     * @throws ValidationException If validation fails
     * @throws UserAlreadyExistsException If email already exists
     * @throws WeakPasswordException If password doesn't meet requirements
     */
    public function execute(RegisterRequest $request): AuthResponse
    {
        // Validate request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Check if email already exists
        if ($this->userRepository->emailExists($request->email)) {
            throw new UserAlreadyExistsException($request->email);
        }

        // Validate password strength
        if (!$this->passwordService->meetsRequirements($request->password)) {
            throw new WeakPasswordException($this->passwordService->getRequirementsMessage());
        }

        // Hash password
        $passwordHash = $this->passwordService->hash($request->password);

        // Create user entity
        $user = new User(
            email: $request->email,
            passwordHash: $passwordHash,
            accountType: $request->accountType,
            isAdmin: false,
        );

        // Begin transaction to ensure atomic creation of user and profile
        $this->transactionService->begin();

        try {
            // Save user
            $this->userRepository->save($user);

            // Create crew or boat profile based on account type
            if ($request->accountType === 'crew') {
                $this->createCrewProfile($user, $request->profile);
            } elseif ($request->accountType === 'boat_owner') {
                $this->createBoatProfile($user, $request->profile);
            }

            // Commit transaction if all successful
            $this->transactionService->commit();
        } catch (\Exception $e) {
            // Rollback transaction on any error
            $this->transactionService->rollBack();
            throw $e;
        }

        // Send admin notification email (don't fail registration if email fails)
        $this->sendAdminNotification($user, $request);
        $this->sendWelcomeEmail($user);

        // Generate JWT token
        $token = $this->tokenService->generate(
            $user->getId(),
            $user->getEmail(),
            $user->getAccountType(),
            $user->isAdmin()
        );

        // Create response
        return new AuthResponse(
            token: $token,
            user: UserResponse::fromEntity($user),
            expiresIn: $this->tokenService->getExpirationMinutes() * 60, // Convert to seconds
        );
    }

    private function parseYesNo(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return strcasecmp($value, 'Yes') === 0
                || strcasecmp($value, 'true') === 0
                || $value === '1';
        }

        return false;
    }

    /**
     * Generate display name from first name and last initial
     *
     * @param string $firstName First name
     * @param string $lastName Last name
     * @return string Display name in format "FirstName L."
     */
    private function generateDisplayName(string $firstName, string $lastName): string
    {
        $lastInitial = mb_substr($lastName, 0, 1);
        return trim($firstName) . $lastInitial;
    }

    /**
     * Resolve a unique display name by appending an incrementing counter on collision.
     *
     * Returns $base if it is available, otherwise tries $base . "2", $base . "3", …
     * until a free name is found.
     *
     * @param string   $base   The desired display name
     * @param callable $exists fn(string $name): bool — returns true when the name is taken
     * @return string          A display name that does not yet exist
     */
    private function resolveUniqueDisplayName(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists($base . $counter)) {
            $counter++;
        }
        return $base . $counter;
    }

    /**
     * Create crew profile
     *
     * @param User $user User entity
     * @param array $profile Crew profile data
     * @return void
     */
    private function createCrewProfile(User $user, array $profile): void
    {
        $crewKey = CrewKey::fromName($profile['firstName'], $profile['lastName']);

        // Check if crew already exists with this key
        $existingCrew = $this->crewRepository->findByKey($crewKey);
        if ($existingCrew !== null) {
            throw new ValidationException(['profile' => 'A crew member with this name already exists']);
        }

        // Generate displayName if not provided
        $displayName = $profile['displayName'] ?? null;
        if ($displayName === null || trim($displayName) === '') {
            $displayName = $this->generateDisplayName(
                $profile['firstName'],
                $profile['lastName']
            );
        }

        // Ensure display name is unique within the crew namespace
        $displayName = $this->resolveUniqueDisplayName(
            $displayName,
            fn($name) => $this->crewRepository->displayNameExists($name)
        );

        // Create crew entity
        $crew = new Crew(
            key: $crewKey,
            displayName: $displayName,
            firstName: $profile['firstName'],
            lastName: $profile['lastName'],
            partnerKey: isset($profile['partnerKey']) ? new CrewKey($profile['partnerKey']) : null,
            mobile: $profile['mobile'] ?? null,
            socialPreference: $this->parseYesNo($profile['socialPreference'] ?? null),
            membershipNumber: $profile['membershipNumber'] ?? null,
            skill: isset($profile['skill']) ? SkillLevel::from((int)$profile['skill']) : SkillLevel::NOVICE,
            experience: $profile['experience'] ?? null,
        );

        // Calculate initial rank
        $rank = $this->rankingService->calculateCrewRank($crew, []);

        // Set rank directly
        $crew->setRank($rank);

        // Link to user
        $crew->setUserId($user->getId());

        // Populate whitelist with all existing boats
        $allBoats = $this->boatRepository->findAll();
        $whitelistedBoatKeys = array_map(
            fn($boat) => $boat->getKey()->toString(),
            $allBoats
        );
        $crew->setWhitelist($whitelistedBoatKeys);

        // Save crew
        $this->crewRepository->save($crew);
    }

    /**
     * Create boat profile
     *
     * @param User $user User entity
     * @param array $profile Boat profile data
     * @return void
     */
    private function createBoatProfile(User $user, array $profile): void
    {
        // Generate displayName if not provided
        $displayName = $profile['displayName'] ?? null;
        if ($displayName === null || trim($displayName) === '') {
            $displayName = $this->generateDisplayName(
                $profile['ownerFirstName'],
                $profile['ownerLastName']
            );
        }

        // Ensure display name is unique within the boat namespace
        $displayName = $this->resolveUniqueDisplayName(
            $displayName,
            fn($name) => $this->boatRepository->displayNameExists($name)
        );

        // Use displayName for boat key
        $boatKey = BoatKey::fromBoatName($displayName);

        // Check if boat already exists with this key (safeguard for explicitly-provided names)
        $existingBoat = $this->boatRepository->findByKey($boatKey);
        if ($existingBoat !== null) {
            throw new ValidationException(['profile' => 'A boat with this name already exists']);
        }

        $willingToCrew = $this->parseYesNo($profile['willingToCrew'] ?? null);

        // Create boat entity
        $boat = new Boat(
            key: $boatKey,
            displayName: $displayName,
            ownerFirstName: $profile['ownerFirstName'],
            ownerLastName: $profile['ownerLastName'],
            ownerMobile: $profile['ownerMobile'] ?? null,
            minBerths: (int)$profile['minBerths'],
            maxBerths: (int)$profile['maxBerths'],
            assistanceRequired: $this->parseYesNo($profile['assistanceRequired'] ?? null),
            socialPreference: $this->parseYesNo($profile['socialPreference'] ?? null),
        );

        // Calculate initial rank
        $rank = $this->rankingService->calculateBoatRank($boat, []);

        // Set rank directly
        $boat->setRank($rank);

        // Override flexibility rank based on willingness to crew (calculateBoatRank uses an empty
        // squad and always returns flexibility=1 regardless of the user's preference)
        $boat->setRankDimension(
            \App\Domain\Enum\BoatRankDimension::FLEXIBILITY,
            $willingToCrew ? 0 : 1
        );

        // Link to user
        $boat->setOwnerUserId($user->getId());

        // Save boat
        $this->boatRepository->save($boat);

        // Add new boat to all existing crew whitelists
        $allCrews = $this->crewRepository->findAll();
        foreach ($allCrews as $crew) {
            $this->crewRepository->addToWhitelist($crew->getKey(), $boatKey);
        }
    }

    /**
     * Send admin notification email about new registration
     *
     * @param User $user User entity
     * @param RegisterRequest $request Registration request data
     * @return void
     */
    private function sendAdminNotification(User $user, RegisterRequest $request): void
    {
        try {
            $adminEmail = $this->config['email']['admin_notification_email'] ?? 'nsc-sdc@nsc.ca';

            if ($request->accountType === 'crew') {
                $subject = sprintf(
                    'New Crew Registration - %s %s',
                    $request->profile['firstName'],
                    $request->profile['lastName']
                );
                $body = $this->emailTemplateService->renderCrewRegistrationNotification($user, $request->profile);
            } else {
                $subject = sprintf(
                    'New Boat Owner Registration - %s',
                    $request->profile['displayName'] ?? $this->generateDisplayName(
                        $request->profile['ownerFirstName'],
                        $request->profile['ownerLastName']
                    )
                );
                $body = $this->emailTemplateService->renderBoatOwnerRegistrationNotification($user, $request->profile);
            }

            $result = $this->emailService->send($adminEmail, $subject, $body);

            if ($result) {
                error_log("Admin notification sent successfully for registration: user_id={$user->getId()}, type={$request->accountType}");
            } else {
                error_log("Failed to send admin notification for registration: user_id={$user->getId()}, type={$request->accountType}");
            }
        } catch (\Exception $e) {
            // Log error but don't fail registration
            error_log("Failed to send admin notification for registration: user_id={$user->getId()}, type={$request->accountType} - {$e->getMessage()}");
        }
    }

    /**
     * Send welcome email to newly registered user
     *
     * @param User $user User entity
     * @return void
     */
    private function sendWelcomeEmail(User $user): void
    {
        try {
            $subject = 'Welcome to the Nepean Sailing Club Social Day Cruising program';
            $body = $this->emailTemplateService->renderWelcomeNotification();

            $futureEventIds = $this->eventRepository->findFutureEvents();
            $events = [];
            foreach ($futureEventIds as $eventId) {
                $eventData = $this->eventRepository->findById(EventId::fromString($eventId));
                if ($eventData === null) {
                    continue;
                }
                $events[] = [
                    'event_id'    => $eventData['event_id'],
                    'date'        => new \DateTimeImmutable($eventData['event_date']),
                    'start_time'  => $eventData['start_time'],
                    'finish_time' => $eventData['finish_time'],
                    'description' => 'Social Day Cruising Event - https://nsc-sdc.ca/events.html',
                    'location'    => 'Nepean Sailing Club',
                ];
            }

            if (!empty($events)) {
                $icsContent = $this->calendarService->generateSeasonCalendar($events);
                $result = $this->emailService->sendWithAttachment(
                    $user->getEmail(), $subject, $body,
                    $icsContent, 'social-day-cruising.ics', 'text/calendar'
                );
            } else {
                $result = $this->emailService->send($user->getEmail(), $subject, $body);
            }

            if ($result) {
                error_log("Welcome email sent: user_id={$user->getId()}");
            } else {
                error_log("Failed to send welcome email: user_id={$user->getId()}");
            }
        } catch (\Exception $e) {
            error_log("Failed to send welcome email: user_id={$user->getId()} - {$e->getMessage()}");
        }
    }

}
