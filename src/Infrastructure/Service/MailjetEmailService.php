<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\EmailServiceInterface;
use Mailjet\Client;
use Mailjet\Resources;

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

    public function __construct(
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?string $defaultFromEmail = null,
        ?string $defaultFromName = null
    ) {
        $this->apiKey          = $apiKey          ?? getenv('MJ_APIKEY_PUBLIC')  ?: '';
        $this->apiSecret       = $apiSecret       ?? getenv('MJ_APIKEY_PRIVATE') ?: '';
        $this->defaultFromEmail = $defaultFromEmail ?? getenv('EMAIL_FROM')       ?: 'noreply@example.com';
        $this->defaultFromName  = $defaultFromName  ?? getenv('EMAIL_FROM_NAME')  ?: 'JAWS System';
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

        if ($this->postMessage($message)) {
            error_log("Email sent successfully to: {$to}");
            return true;
        }

        error_log("Email send failed to: {$to}");
        return false;
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

        if ($this->postMessage($message)) {
            error_log("BCC email sent to " . count($bcc) . " recipients via: {$to}");
            return true;
        }

        error_log("BCC email send failed to: {$to}");
        return false;
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

        if ($this->postMessage($message)) {
            error_log("CC email sent to " . count($cc) . " recipients via: {$to}");
            return true;
        }

        error_log("CC email send failed to: {$to}");
        return false;
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

        if ($this->postMessage($message)) {
            error_log("Email with attachment sent successfully to: {$to}");
            return true;
        }

        error_log("Email with attachment send failed to: {$to}");
        return false;
    }

    private function postMessage(array $message): bool
    {
        $mj    = $this->newMailjetClient();
        $delay = self::RETRY_BASE_DELAY;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            try {
                $response = $mj->post(Resources::$Email, ['body' => ['Messages' => [$message]]]);
            } catch (\Exception $e) {
                // Network-level failure (DNS, timeout, connection refused)
                if ($attempt <= self::MAX_RETRIES) {
                    error_log("Mailjet network error (attempt {$attempt}), retrying in {$delay}s: " . $e->getMessage());
                    $this->doSleep($delay);
                    $delay *= 2;
                    continue;
                }
                error_log("Mailjet network error after " . (self::MAX_RETRIES + 1) . " attempts: " . $e->getMessage());
                return false;
            }

            if ($response->success()) {
                return true;
            }

            $status = $response->getStatus();

            // 4xx = permanent failure (bad credentials, malformed request) — don't retry
            if ($status >= 400 && $status < 500) {
                error_log("Mailjet permanent failure (HTTP {$status}) — not retrying");
                return false;
            }

            // 5xx = transient server error — retry
            if ($attempt <= self::MAX_RETRIES) {
                error_log("Mailjet transient failure (HTTP {$status}, attempt {$attempt}), retrying in {$delay}s");
                $this->doSleep($delay);
                $delay *= 2;
                continue;
            }

            error_log("Mailjet transient failure (HTTP {$status}) after " . (self::MAX_RETRIES + 1) . " attempts");
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
