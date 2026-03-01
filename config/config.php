<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * Central configuration file for the application.
 * Environment variables can be loaded from .env file if needed.
 */

return [
    // Database
    'database' => [
        'path' => getenv('DB_PATH') ?: __DIR__ . '/../database/jaws.db',
    ],

    // SMTP Email Configuration (PHPMailer)
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'email-smtp.ca-central-1.amazonaws.com',
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'secure' => getenv('SMTP_SECURE') ?: 'tls',
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
    ],

    // Mailjet Email API
    'mailjet' => [
        'api_key'    => getenv('MJ_APIKEY_PUBLIC')  ?: '',
        'api_secret' => getenv('MJ_APIKEY_PRIVATE') ?: '',
    ],

    // Email Settings
    'email' => [
        'from_address' => getenv('EMAIL_FROM') ?: 'noreply@nsc-sdc.ca',
        'from_name' => getenv('EMAIL_FROM_NAME') ?: 'Nepean Sailing Club - Social Day Cruising',
        'admin_notification_email' => getenv('ADMIN_NOTIFICATION_EMAIL') ?: 'nsc-sdc@nsc.ca',
    ],

    // Application
    'app' => [
        'debug' => getenv('APP_DEBUG') === 'true',
        'timezone' => getenv('APP_TIMEZONE') ?: 'America/Toronto',
        'url' => getenv('APP_URL') ?: 'http://localhost',
    ],

    // JWT Authentication
    'jwt' => [
        'secret' => getenv('JWT_SECRET') ?: 'CHANGE_IN_PRODUCTION_MIN_32_CHARS',
        'expiration_minutes' => (int)(getenv('JWT_EXPIRATION_MINUTES') ?: 60),
    ],

    // CORS (for future frontend)
    'cors' => [
        'allowed_origins' => explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*'),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => explode(',', getenv('CORS_ALLOWED_HEADERS') ?: 'Content-Type,Authorization'),
    ],
];
