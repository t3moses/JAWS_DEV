<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\EmailServiceInterface;
use Mailjet\Client;
use Mailjet\Resources;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Mailjet Email Service
 *
 * Implements email sending via the Mailjet Send API v3.1.
 */
class MailjetEmailService implements EmailServiceInterface
{
    private const MAX_RETRIES = 2;      // 3 total attempts (initial + 2 retries)
    private const RETRY_BASE_DELAY = 1; // seconds; doubles each retry (1s, 2s)

    private string $apiKey;
    private string $apiSecret;
    private string $defaultFromEmail;
    private string $defaultFromName;
    private LoggerInterface $logger;

    public function __construct(
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?string $defaultFromEmail = null,
        ?string $defaultFromName = null,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey           = $apiKey           ?? getenv('MJ_APIKEY_PUBLIC')  ?: '';
        $this->apiSecret        = $apiSecret        ?? getenv('MJ_APIKEY_PRIVATE') ?: '';
        $this->defaultFromEmail = $defaultFromEmail ?? getenv('EMAIL_FROM')        ?: 'noreply@example.com';
        $this->defaultFromName  = $defaultFromName  ?? getenv('EMAIL_FROM_NAME')   ?: 'Social Day Cruising';
        $this->logger           = $logger           ?? new NullLogger();
    }

    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        $message = [
            'From' => [
                'Email' => $fromEmail ?? $this->defaultFromEmail,
                'Name'  => $fromName  ?? $this->defaultFromName,
            ],
            'To' => [
                ['Email' => $to],
            ],
            'Subject'  => $subject,
            'HTMLPart' => $body,
        ];

        return $this->postMessage($message);
    }

    public function sendBulk(
        array $recipients,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): array {
        $results = [];

        foreach ($recipients as $recipient) {
            $results[$recipient] = $this->send($recipient, $subject, $body, $fromName, $fromEmail);
        }

        return $results;
    }

    public function sendWithBcc(
        string $to,
        array $bcc,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        $bccList = array_map(fn(string $email) => ['Email' => $email], $bcc);

        $message = [
            'From' => [
                'Email' => $fromEmail ?? $this->defaultFromEmail,
                'Name'  => $fromName  ?? $this->defaultFromName,
            ],
            'To' => [
                ['Email' => $to],
            ],
            'Bcc'      => $bccList,
            'Subject'  => $subject,
            'HTMLPart' => $body,
        ];

        return $this->postMessage($message);
    }

    public function sendWithCc(
        string $to,
        array $cc,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        $ccList = array_map(fn(string $email) => ['Email' => $email], $cc);

        $message = [
            'From' => [
                'Email' => $fromEmail ?? $this->defaultFromEmail,
                'Name'  => $fromName  ?? $this->defaultFromName,
            ],
            'To' => [
                ['Email' => $to],
            ],
            'Cc'       => $ccList,
            'Subject'  => $subject,
            'HTMLPart' => $body,
        ];

        return $this->postMessage($message);
    }

    public function sendWithAttachment(
        string $to,
        string $subject,
        string $body,
        string $attachmentContent,
        string $attachmentFilename,
        string $attachmentMimeType = 'application/octet-stream',
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        $message = [
            'From' => [
                'Email' => $fromEmail ?? $this->defaultFromEmail,
                'Name'  => $fromName  ?? $this->defaultFromName,
            ],
            'To' => [
                ['Email' => $to],
            ],
            'Subject'  => $subject,
            'HTMLPart' => $body,
            'Attachments' => [[
                'ContentType'   => $attachmentMimeType,
                'Filename'      => $attachmentFilename,
                'Base64Content' => base64_encode($attachmentContent),
            ]],
        ];

        return $this->postMessage($message);
    }

    private function postMessage(array $message): bool
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            $this->logger->error('email.credentials_missing', [
                'message' => 'Mailjet API key or secret is empty',
            ]);
            return false;
        }

        $mj    = $this->newMailjetClient();
        $delay = self::RETRY_BASE_DELAY;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            try {
                $response = $mj->post(Resources::$Email, ['body' => ['Messages' => [$message]]]);
            } catch (\Exception $e) {
                // Network-level failure (DNS, timeout, connection refused)
                if ($attempt <= self::MAX_RETRIES) {
                    $this->logger->warning('email.retry', ['attempt' => $attempt, 'delay' => $delay, 'error' => $e->getMessage()]);
                    $this->doSleep($delay);
                    $delay *= 2;
                    continue;
                }
                $this->logger->error('email.network_exhausted', ['attempts' => self::MAX_RETRIES + 1, 'error' => $e->getMessage()]);
                return false;
            }

            if ($response->success()) {
                return true;
            }

            $status = $response->getStatus();

            // 4xx = permanent failure (bad credentials, malformed request) — don't retry
            if ($status >= 400 && $status < 500) {
                $this->logger->error('email.permanent_failure', ['http_status' => $status]);
                return false;
            }

            // 5xx = transient server error — retry
            if ($attempt <= self::MAX_RETRIES) {
                $this->logger->warning('email.transient_retry', ['http_status' => $status, 'attempt' => $attempt, 'delay' => $delay]);
                $this->doSleep($delay);
                $delay *= 2;
                continue;
            }

            $this->logger->error('email.transient_exhausted', ['http_status' => $status, 'attempts' => self::MAX_RETRIES + 1]);
            return false;
        }

        return false; // unreachable, but satisfies static analysis
    }

    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function newMailjetClient(): Client
    {
        return new Client($this->apiKey, $this->apiSecret, true, ['version' => 'v3.1']);
    }

    protected function doSleep(int $seconds): void
    {
        sleep($seconds);
    }
}
