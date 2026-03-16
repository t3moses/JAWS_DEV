<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase\Auth;

use App\Application\DTO\Request\ForgotPasswordRequest;
use App\Application\Exception\ValidationException;
use App\Application\Port\Repository\PasswordResetTokenRepositoryInterface;
use App\Application\Port\Repository\UserRepositoryInterface;
use App\Application\Port\Service\EmailServiceInterface;
use App\Application\Port\Service\EmailTemplateServiceInterface;
use App\Application\UseCase\Auth\ForgotPasswordUseCase;
use App\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ForgotPasswordUseCaseTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private PasswordResetTokenRepositoryInterface $tokenRepository;
    private EmailServiceInterface $emailService;
    private EmailTemplateServiceInterface $emailTemplateService;
    private array $config;
    private ForgotPasswordUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository      = $this->createMock(UserRepositoryInterface::class);
        $this->tokenRepository     = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $this->emailService        = $this->createMock(EmailServiceInterface::class);
        $this->emailTemplateService = $this->createMock(EmailTemplateServiceInterface::class);

        $this->config = [
            'app' => ['url' => 'https://example.com'],
        ];

        $this->emailTemplateService->method('renderPasswordResetNotification')
            ->willReturn('<html>Reset your password: <a href="...">link</a></html>');

        $this->useCase = new ForgotPasswordUseCase(
            $this->userRepository,
            $this->tokenRepository,
            $this->emailService,
            $this->emailTemplateService,
            $this->config,
            new NullLogger(),
        );
    }

    public function testThrowsValidationExceptionOnEmptyEmail(): void
    {
        $this->expectException(ValidationException::class);

        $this->useCase->execute(new ForgotPasswordRequest(''));
    }

    public function testThrowsValidationExceptionOnInvalidEmailFormat(): void
    {
        $this->expectException(ValidationException::class);

        $this->useCase->execute(new ForgotPasswordRequest('not-an-email'));
    }

    public function testReturnsSuccessfullyForUnknownEmail(): void
    {
        // Enumeration protection — must not throw or send email
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->emailService->expects($this->never())->method('send');
        $this->tokenRepository->expects($this->never())->method('save');

        $this->useCase->execute(new ForgotPasswordRequest('unknown@example.com'));

        // No exception = pass
        $this->addToAssertionCount(1);
    }

    public function testSendsResetEmailToKnownUser(): void
    {
        $user = $this->makeUser(42, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $this->emailService->expects($this->once())
            ->method('send')
            ->with('user@example.com', 'Reset your password', $this->anything())
            ->willReturn(true);

        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));
    }

    public function testDeletesPriorTokensBeforeSavingNew(): void
    {
        $user = $this->makeUser(7, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->emailService->method('send')->willReturn(true);

        $this->tokenRepository->expects($this->once())
            ->method('deleteByUserId')
            ->with(7);

        $this->tokenRepository->expects($this->once())
            ->method('save');

        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));
    }

    public function testStoresHashedTokenNotPlainToken(): void
    {
        $user = $this->makeUser(1, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->emailService->method('send')->willReturn(true);

        $savedHash = null;
        $this->tokenRepository->method('save')
            ->willReturnCallback(function (int $userId, string $hash) use (&$savedHash) {
                $savedHash = $hash;
            });

        $resetUrl = null;
        $this->emailTemplateService->method('renderPasswordResetNotification')
            ->willReturnCallback(function (string $url) use (&$resetUrl) {
                $resetUrl = $url;
                return '<html>reset</html>';
            });

        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));

        // Extract plain token from URL and verify the stored hash matches
        $this->assertNotNull($resetUrl);
        parse_str(parse_url($resetUrl, PHP_URL_QUERY), $params);
        $plainToken = $params['token'];

        $this->assertNotNull($savedHash);
        $this->assertEquals(hash('sha256', $plainToken), $savedHash);
        $this->assertNotEquals($plainToken, $savedHash);
    }

    public function testTokenExpiresInApproximatelyOneHour(): void
    {
        $user = $this->makeUser(1, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->emailService->method('send')->willReturn(true);

        $savedExpiry = null;
        $this->tokenRepository->method('save')
            ->willReturnCallback(function (int $userId, string $hash, \DateTimeImmutable $expiresAt) use (&$savedExpiry) {
                $savedExpiry = $expiresAt;
            });

        $before = new \DateTimeImmutable('+59 minutes');
        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));
        $after = new \DateTimeImmutable('+61 minutes');

        $this->assertNotNull($savedExpiry);
        $this->assertGreaterThan($before, $savedExpiry);
        $this->assertLessThan($after, $savedExpiry);
    }

    public function testResetUrlContainsPlainToken(): void
    {
        $user = $this->makeUser(1, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->emailService->method('send')->willReturn(true);

        $capturedUrl = null;
        $this->emailTemplateService->method('renderPasswordResetNotification')
            ->willReturnCallback(function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return '<html>reset</html>';
            });

        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));

        $this->assertNotNull($capturedUrl);
        $this->assertStringStartsWith('https://example.com/reset-password.html?token=', $capturedUrl);
    }

    public function testSucceedsWhenEmailServiceFails(): void
    {
        $user = $this->makeUser(1, 'user@example.com');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $this->emailService->method('send')
            ->willThrowException(new \RuntimeException('SMTP unavailable'));

        // Must not propagate the exception
        $this->useCase->execute(new ForgotPasswordRequest('user@example.com'));

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------

    private function makeUser(int $id, string $email): User
    {
        $user = new User($email, 'hashed_password', 'crew');
        $reflection = new \ReflectionClass($user);
        $prop = $reflection->getProperty('id');
        $prop->setValue($user, $id);
        return $user;
    }
}
