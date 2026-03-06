<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use App\Application\Port\Service\EmailServiceInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * PHPMailer Email Service
 *
 * Implements email sending via SMTP using PHPMailer.
 */
class PhpMailerEmailService implements EmailServiceInterface
{
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $smtpSecure;
    private string $defaultFromEmail;
    private string $defaultFromName;
    private bool $debug;

    public function __construct(
        ?string $smtpHost = null,
        ?int $smtpPort = null,
        ?string $smtpUsername = null,
        ?string $smtpPassword = null,
        ?string $smtpSecure = null,
        ?string $defaultFromEmail = null,
        ?string $defaultFromName = null,
        ?bool $debug = null
    ) {
        // Use environment variables if parameters not provided
        $this->smtpHost = $smtpHost
            ?? getenv('SMTP_HOST') ?: 'email-smtp.ca-central-1.amazonaws.com';
        $this->smtpPort = $smtpPort
            ?? (int)(getenv('SMTP_PORT') ?: 587);
        $this->smtpUsername = $smtpUsername
            ?? getenv('SMTP_USERNAME') ?: '';
        $this->smtpPassword = $smtpPassword
            ?? getenv('SMTP_PASSWORD') ?: '';
        $smtpSecureEnv = getenv('SMTP_SECURE');
        $this->smtpSecure = $smtpSecure
            ?? ($smtpSecureEnv !== false ? $smtpSecureEnv : PHPMailer::ENCRYPTION_STARTTLS);
        $this->defaultFromEmail = $defaultFromEmail
            ?? getenv('EMAIL_FROM') ?: 'noreply@example.com';
        $this->defaultFromName = $defaultFromName
            ?? getenv('EMAIL_FROM_NAME') ?: 'Social Day Cruising';
        $this->debug = $debug
            ?? (getenv('APP_DEBUG') === 'true');
    }

    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        try {
            $mail = $this->createMailer();

            // Set sender
            $mail->setFrom(
                $fromEmail ?? $this->defaultFromEmail,
                $fromName ?? $this->defaultFromName
            );

            // Set recipient
            $mail->addAddress($to);

            // Set email content
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(true);  // Support HTML email bodies

            // Send email
            $result = $mail->send();

            if ($result) {
                error_log("Email sent successfully to: {$to}");
                return true;
            }

            error_log("Email send failed to: {$to} - No result returned");
            return false;
        } catch (PHPMailerException $e) {
            error_log("Email send failed to: {$to} - PHPMailer Error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Email send failed to: {$to} - General Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $mail = $this->createMailer();

            $mail->setFrom(
                $fromEmail ?? $this->defaultFromEmail,
                $fromName ?? $this->defaultFromName
            );

            $mail->addAddress($to);

            foreach ($bcc as $bccEmail) {
                $mail->addBCC($bccEmail);
            }

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(true);

            $result = $mail->send();

            if ($result) {
                error_log("BCC email sent to " . count($bcc) . " recipients via: {$to}");
                return true;
            }

            error_log("BCC email send failed - No result returned");
            return false;
        } catch (PHPMailerException $e) {
            error_log("BCC email send failed - PHPMailer Error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("BCC email send failed - General Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendWithCc(
        string $to,
        array $cc,
        string $subject,
        string $body,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): bool {
        try {
            $mail = $this->createMailer();

            $mail->setFrom(
                $fromEmail ?? $this->defaultFromEmail,
                $fromName ?? $this->defaultFromName
            );

            $mail->addAddress($to);

            foreach ($cc as $ccAddress) {
                if ($this->validateEmail($ccAddress)) {
                    $mail->addCC($ccAddress);
                }
            }

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(true);

            $result = $mail->send();

            if ($result) {
                error_log("Email (with CC) sent successfully to: {$to}");
                return true;
            }

            error_log("Email (with CC) send failed to: {$to} - No result returned");
            return false;
        } catch (PHPMailerException $e) {
            error_log("Email (with CC) send failed to: {$to} - PHPMailer Error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Email (with CC) send failed to: {$to} - General Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $mail = $this->createMailer();

            $mail->setFrom(
                $fromEmail ?? $this->defaultFromEmail,
                $fromName ?? $this->defaultFromName
            );

            $mail->addAddress($to);

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(true);

            $mail->addStringAttachment(
                $attachmentContent,
                $attachmentFilename,
                PHPMailer::ENCODING_BASE64,
                $attachmentMimeType
            );

            $result = $mail->send();

            if ($result) {
                error_log("Email with attachment sent successfully to: {$to}");
                return true;
            }

            error_log("Email with attachment send failed to: {$to} - No result returned");
            return false;
        } catch (PHPMailerException $e) {
            error_log("Email with attachment send failed to: {$to} - PHPMailer Error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Email with attachment send failed to: {$to} - General Error: " . $e->getMessage());
            return false;
        }
    }

    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Create configured PHPMailer instance
     *
     * @return PHPMailer
     * @throws PHPMailerException
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);  // Enable exceptions

        // SMTP configuration (based on working test script from issue #37)
        $mail->isSMTP();
        $mail->Host = $this->smtpHost;
        $mail->Port = $this->smtpPort;
        $mail->SMTPAuth = $this->smtpUsername !== '';
        $mail->Username = $this->smtpUsername;
        $mail->Password = $this->smtpPassword;
        $mail->SMTPSecure = $this->smtpSecure;

        // Character encoding
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        // Debug mode (only in development)
        if ($this->debug) {
            $mail->SMTPDebug = 2;  // Enable verbose debug output
            $mail->Debugoutput = function ($str, $level) {
                error_log("PHPMailer Debug: {$str}");
            };
        } else {
            $mail->SMTPDebug = 0;  // Disable debug output in production
        }

        // Timeout settings
        $mail->Timeout = 30;  // Connection timeout (seconds)

        // Disable SSL verification for local development (localhost)
        $appEnv = getenv('APP_ENV') ?: '';
        if (in_array($appEnv, ['development', 'local']) && strpos($this->smtpHost, 'localhost') !== false) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
        }

        return $mail;
    }
}
