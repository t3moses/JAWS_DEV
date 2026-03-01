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
        $mj = new Client($this->apiKey, $this->apiSecret, true, ['version' => 'v3.1']);

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

        $response = $mj->post(Resources::$Email, ['body' => ['Messages' => [$message]]]);

        if ($response->success()) {
            error_log("Email sent successfully to: {$to}");
            return true;
        }

        error_log("Email send failed to: {$to} - Status: " . $response->getStatus());
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
        $mj = new Client($this->apiKey, $this->apiSecret, true, ['version' => 'v3.1']);

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

        $response = $mj->post(Resources::$Email, ['body' => ['Messages' => [$message]]]);

        if ($response->success()) {
            error_log("BCC email sent to " . count($bcc) . " recipients via: {$to}");
            return true;
        }

        error_log("BCC email send failed - Status: " . $response->getStatus());
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
        $mj = new Client($this->apiKey, $this->apiSecret, true, ['version' => 'v3.1']);

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

        $response = $mj->post(Resources::$Email, ['body' => ['Messages' => [$message]]]);

        if ($response->success()) {
            error_log("CC email sent to " . count($cc) . " recipients via: {$to}");
            return true;
        }

        error_log("CC email send failed - Status: " . $response->getStatus());
        return false;
    }

    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
