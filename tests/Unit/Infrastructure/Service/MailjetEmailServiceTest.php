<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Service;

use App\Infrastructure\Service\MailjetEmailService;
use Mailjet\Client;
use Mailjet\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MailjetEmailService.
 *
 * Uses an anonymous subclass to intercept newMailjetClient() and doSleep()
 * so tests run without real HTTP calls or sleep delays.
 */
class MailjetEmailServiceTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a testable MailjetEmailService backed by a fake Mailjet client.
     *
     * Returns [$service, $state] where:
     *   $state->messages  – message payloads captured from each post() call
     *   $state->sleeps    – second-values passed to each doSleep() call
     *
     * @param list<Response|\Throwable> $postResponses
     *   Responses (or exceptions) consumed in sequence.
     *   Once exhausted, subsequent post() calls return a success response.
     */
    private function makeService(array $postResponses = []): array
    {
        $idx            = 0;
        $state          = (object)['messages' => [], 'sleeps' => []];
        $defaultSuccess = $this->makeResponse(true);

        $fakeClient = $this->createMock(Client::class);
        $fakeClient->method('post')
            ->willReturnCallback(
                function ($resource, $params) use (&$idx, $postResponses, $state, $defaultSuccess) {
                    if (isset($params['body']['Messages'][0])) {
                        $state->messages[] = $params['body']['Messages'][0];
                    }

                    if (!array_key_exists($idx, $postResponses)) {
                        return $defaultSuccess;
                    }

                    $item = $postResponses[$idx++];

                    if ($item instanceof \Throwable) {
                        throw $item;
                    }

                    return $item;
                }
            );

        $svc = new class('test-key', 'test-secret', 'from@test.com', 'Test Sender', $fakeClient, $state)
            extends MailjetEmailService
        {
            private Client $fakeClient;
            private object $state;

            public function __construct(
                string $apiKey,
                string $apiSecret,
                string $defaultFromEmail,
                string $defaultFromName,
                Client $fakeClient,
                object $state
            ) {
                parent::__construct($apiKey, $apiSecret, $defaultFromEmail, $defaultFromName);
                $this->fakeClient = $fakeClient;
                $this->state      = $state;
            }

            protected function newMailjetClient(): Client
            {
                return $this->fakeClient;
            }

            protected function doSleep(int $seconds): void
            {
                $this->state->sleeps[] = $seconds;
            }
        };

        return [$svc, $state];
    }

    private function makeResponse(bool $success, int $status = 200): Response
    {
        $r = $this->createMock(Response::class);
        $r->method('success')->willReturn($success);
        $r->method('getStatus')->willReturn($status);
        return $r;
    }

    // ── Group 1: validateEmail() ──────────────────────────────────────────────

    public function testValidateEmailReturnsTrueForValidAddress(): void
    {
        [$svc] = $this->makeService();
        $this->assertTrue($svc->validateEmail('user@example.com'));
    }

    public function testValidateEmailReturnsFalseForMissingAt(): void
    {
        [$svc] = $this->makeService();
        $this->assertFalse($svc->validateEmail('notanemail'));
    }

    public function testValidateEmailReturnsFalseForEmptyString(): void
    {
        [$svc] = $this->makeService();
        $this->assertFalse($svc->validateEmail(''));
    }

    // ── Group 2: send() ───────────────────────────────────────────────────────

    public function testSendReturnsTrueOnSuccess(): void
    {
        [$svc] = $this->makeService([$this->makeResponse(true)]);
        $this->assertTrue($svc->send('to@example.com', 'Subject', '<p>Body</p>'));
    }

    public function testSendReturnsFalseOnFailure(): void
    {
        // 4xx causes immediate failure (no retries, only one response needed)
        [$svc] = $this->makeService([$this->makeResponse(false, 400)]);
        $this->assertFalse($svc->send('to@example.com', 'Subject', '<p>Body</p>'));
    }

    // ── Group 3: sendBulk() ───────────────────────────────────────────────────

    public function testSendBulkReturnsArrayKeyedByEmailAddress(): void
    {
        [$svc] = $this->makeService([
            $this->makeResponse(true),
            $this->makeResponse(true),
        ]);
        $results = $svc->sendBulk(['a@example.com', 'b@example.com'], 'Sub', 'Body');

        $this->assertArrayHasKey('a@example.com', $results);
        $this->assertArrayHasKey('b@example.com', $results);
    }

    public function testSendBulkResultsReflectIndividualOutcomes(): void
    {
        [$svc] = $this->makeService([
            $this->makeResponse(true),
            $this->makeResponse(false, 400),
        ]);
        $results = $svc->sendBulk(['ok@example.com', 'fail@example.com'], 'Sub', 'Body');

        $this->assertTrue($results['ok@example.com']);
        $this->assertFalse($results['fail@example.com']);
    }

    public function testSendBulkWithEmptyRecipientsReturnsEmptyArray(): void
    {
        [$svc] = $this->makeService();
        $this->assertSame([], $svc->sendBulk([], 'Sub', 'Body'));
    }

    // ── Group 4: sendWithBcc() ────────────────────────────────────────────────

    public function testSendWithBccReturnsTrueOnSuccess(): void
    {
        [$svc] = $this->makeService([$this->makeResponse(true)]);
        $result = $svc->sendWithBcc('to@example.com', ['bcc@example.com'], 'Sub', 'Body');
        $this->assertTrue($result);
    }

    public function testSendWithBccMapsBccRecipientsToEmailFormat(): void
    {
        [$svc, $state] = $this->makeService([$this->makeResponse(true)]);
        $svc->sendWithBcc('to@example.com', ['bcc1@example.com', 'bcc2@example.com'], 'Sub', 'Body');

        $this->assertSame(
            [['Email' => 'bcc1@example.com'], ['Email' => 'bcc2@example.com']],
            $state->messages[0]['Bcc']
        );
    }

    // ── Group 5: sendWithCc() ─────────────────────────────────────────────────

    public function testSendWithCcReturnsTrueOnSuccess(): void
    {
        [$svc] = $this->makeService([$this->makeResponse(true)]);
        $result = $svc->sendWithCc('to@example.com', ['cc@example.com'], 'Sub', 'Body');
        $this->assertTrue($result);
    }

    public function testSendWithCcMapsCcRecipientsToEmailFormat(): void
    {
        [$svc, $state] = $this->makeService([$this->makeResponse(true)]);
        $svc->sendWithCc('to@example.com', ['cc1@example.com', 'cc2@example.com'], 'Sub', 'Body');

        $this->assertSame(
            [['Email' => 'cc1@example.com'], ['Email' => 'cc2@example.com']],
            $state->messages[0]['Cc']
        );
    }

    // ── Group 6: Retry logic ──────────────────────────────────────────────────

    public function testNetworkExceptionOnAllAttemptsReturnsFalse(): void
    {
        [$svc] = $this->makeService([
            new \RuntimeException('network error 1'),
            new \RuntimeException('network error 2'),
            new \RuntimeException('network error 3'),
        ]);
        $this->assertFalse($svc->send('to@example.com', 'Sub', 'Body'));
    }

    public function testNetworkExceptionOnFirstAttemptThenSuccessReturnsTrue(): void
    {
        [$svc] = $this->makeService([
            new \RuntimeException('network error'),
            $this->makeResponse(true),
        ]);
        $this->assertTrue($svc->send('to@example.com', 'Sub', 'Body'));
    }

    public function testHttp5xxOnFirstAttemptThenSuccessReturnsTrue(): void
    {
        [$svc] = $this->makeService([
            $this->makeResponse(false, 503),
            $this->makeResponse(true),
        ]);
        $this->assertTrue($svc->send('to@example.com', 'Sub', 'Body'));
    }

    public function testHttp5xxExhaustsAllRetriesReturnsFalse(): void
    {
        [$svc] = $this->makeService([
            $this->makeResponse(false, 503),
            $this->makeResponse(false, 503),
            $this->makeResponse(false, 503),
        ]);
        $this->assertFalse($svc->send('to@example.com', 'Sub', 'Body'));
    }

    public function testHttp4xxReturnsFalseImmediatelyWithoutRetry(): void
    {
        [$svc, $state] = $this->makeService([
            $this->makeResponse(false, 401),
        ]);
        $result = $svc->send('to@example.com', 'Sub', 'Body');

        $this->assertFalse($result);
        $this->assertEmpty($state->sleeps);
    }

    public function testSleepDelaysAreCorrectOnExceptionRetries(): void
    {
        // 3 exceptions → 2 sleeps before the final (exhausted) attempt
        [$svc, $state] = $this->makeService([
            new \RuntimeException('e1'),
            new \RuntimeException('e2'),
            new \RuntimeException('e3'),
        ]);
        $svc->send('to@example.com', 'Sub', 'Body');

        $this->assertSame([1, 2], $state->sleeps);
    }

    public function testSleepDelaysAreCorrectOn5xxRetries(): void
    {
        // 3 × 5xx → 2 sleeps before the final (exhausted) attempt
        [$svc, $state] = $this->makeService([
            $this->makeResponse(false, 503),
            $this->makeResponse(false, 503),
            $this->makeResponse(false, 503),
        ]);
        $svc->send('to@example.com', 'Sub', 'Body');

        $this->assertSame([1, 2], $state->sleeps);
    }

    // ── Group 6b: sendWithAttachment() ───────────────────────────────────────

    public function testSendWithAttachmentIncludesBase64EncodedPayload(): void
    {
        [$service, $state] = $this->makeService();

        $result = $service->sendWithAttachment(
            'to@example.com', 'Subject', '<p>Body</p>',
            'ICAL_CONTENT', 'events.ics', 'text/calendar'
        );

        $this->assertTrue($result);
        $this->assertCount(1, $state->messages);
        $msg = $state->messages[0];
        $this->assertArrayHasKey('Attachments', $msg);
        $this->assertEquals('text/calendar', $msg['Attachments'][0]['ContentType']);
        $this->assertEquals('events.ics', $msg['Attachments'][0]['Filename']);
        $this->assertEquals(base64_encode('ICAL_CONTENT'), $msg['Attachments'][0]['Base64Content']);
    }

    // ── Group 7: Custom from name / email ─────────────────────────────────────

    public function testSendWithExplicitFromUsesProvidedValues(): void
    {
        [$svc, $state] = $this->makeService([$this->makeResponse(true)]);
        $svc->send('to@example.com', 'Sub', 'Body', 'Custom Name', 'custom@example.com');

        $this->assertSame('custom@example.com', $state->messages[0]['From']['Email']);
        $this->assertSame('Custom Name',        $state->messages[0]['From']['Name']);
    }

    public function testSendWithoutFromOverridesUsesConstructorDefaults(): void
    {
        [$svc, $state] = $this->makeService([$this->makeResponse(true)]);
        $svc->send('to@example.com', 'Sub', 'Body');

        $this->assertSame('from@test.com', $state->messages[0]['From']['Email']);
        $this->assertSame('Test Sender',   $state->messages[0]['From']['Name']);
    }

    // ── Group 8: Credential validation ────────────────────────────────────────

    public function testSendReturnsFalseWhenApiKeyIsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('email.credentials_missing', $this->anything());

        $svc = new MailjetEmailService('', 'secret', 'from@test.com', 'Test', $logger);
        $this->assertFalse($svc->send('to@example.com', 'Sub', 'Body'));
    }

    public function testSendReturnsFalseWhenApiSecretIsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('email.credentials_missing', $this->anything());

        $svc = new MailjetEmailService('key', '', 'from@test.com', 'Test', $logger);
        $this->assertFalse($svc->send('to@example.com', 'Sub', 'Body'));
    }
}
