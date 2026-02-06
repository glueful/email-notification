<?php

declare(strict_types=1);

/*
 * Email Notification Extension Configuration (modern location)
 *
 * Extension-specific configuration for the Email Notification Channel.
 * Core mail settings (SMTP, from address, etc.) are loaded from config/services.php
 * This file contains only extension-specific features and behaviors.
 */

return [
    // Templates configuration (extension-specific)
    'templates' => [
        'extension_path' => __DIR__ . '/../src/Templates/html',
        'extension_mappings' => [
            'verification' => 'verification',
            'password-reset' => 'password-reset',
            'welcome' => 'welcome',
            'alert' => 'alert',
            'default' => 'default',
        ],
        'processing' => [
            'minify_html' => env('MAIL_MINIFY_HTML', false),
            'inline_css' => env('MAIL_INLINE_CSS', true),
            'auto_text_version' => true,
        ],
        'extension_variables' => [
            'extension_version' => 'dev', // Overridden at runtime from composer.json
            'powered_by' => 'Glueful EmailNotification Extension',
        ],
    ],

    // Email sending limits
    'rate_limit' => [
        'enabled' => env('MAIL_RATE_LIMIT_ENABLED', true),
        'max_per_minute' => env('MAIL_RATE_LIMIT_PER_MINUTE', 10),
        'max_per_hour' => env('MAIL_RATE_LIMIT_PER_HOUR', 100),
        'max_per_day' => env('MAIL_RATE_LIMIT_PER_DAY', 1000),
    ],

    // Queue integration
    'queue' => [
        'enabled' => env('MAIL_QUEUE_ENABLED', true),
        'connection' => env('MAIL_QUEUE_CONNECTION', 'default'),
        'queue_name' => env('MAIL_QUEUE_NAME', 'emails'),
        'retry_after' => env('MAIL_QUEUE_RETRY_AFTER', 90),
        'max_attempts' => env('MAIL_QUEUE_MAX_ATTEMPTS', 3),
        'priority' => env('MAIL_QUEUE_PRIORITY', 5),
        'timeout' => env('MAIL_QUEUE_TIMEOUT', 120),
    ],

    // Event handling
    'events' => [
        'enabled' => env('MAIL_EVENTS_ENABLED', true),
        'listeners' => [
            'Glueful\\Extensions\\EmailNotification\\Listeners\\EmailNotificationListener'
        ],
        'fire_events' => [
            'email.sending' => true,
            'email.sent' => true,
            'email.failed' => true,
        ],
    ],

    // Retry configuration
    'retry' => [
        'enabled' => env('MAIL_RETRY_ENABLED', true),
        'max_attempts' => env('MAIL_RETRY_MAX_ATTEMPTS', 3),
        'delay' => env('MAIL_RETRY_DELAY', 300),
        'backoff' => env('MAIL_RETRY_BACKOFF', 'exponential'),
        'jitter' => env('MAIL_RETRY_JITTER', true),
    ],

    // Monitoring and analytics
    'monitoring' => [
        'enabled' => env('MAIL_MONITORING_ENABLED', true),
        'track_opens' => env('MAIL_TRACK_OPENS', false),
        'track_clicks' => env('MAIL_TRACK_CLICKS', false),
        'bounce_handling' => env('MAIL_BOUNCE_HANDLING', true),
    ],

    // Debug and development
    'debug' => [
        'enabled' => env('MAIL_DEBUG', false),
        'log_all_emails' => env('MAIL_LOG_ALL', false),
        'preview_mode' => env('MAIL_PREVIEW_MODE', false),
        'test_email' => env('MAIL_TEST_EMAIL', null),
    ],

    // Security features
    'security' => [
        'verify_ssl' => env('MAIL_VERIFY_SSL', true),
        'allowed_domains' => env('MAIL_ALLOWED_DOMAINS', null),
        'blocked_domains' => env('MAIL_BLOCKED_DOMAINS', null),
        'content_scanning' => env('MAIL_CONTENT_SCANNING', false),
    ],

    // Performance
    'performance' => [
        'connection_pooling' => env('MAIL_CONNECTION_POOLING', false),
        'batch_sending' => env('MAIL_BATCH_SENDING', true),
        'batch_size' => env('MAIL_BATCH_SIZE', 50),
        'concurrent_connections' => env('MAIL_CONCURRENT_CONNECTIONS', 3),
    ],
];
