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
        'extension_variables' => [
            'extension_version' => 'dev', // Overridden at runtime from composer.json
            'powered_by' => 'Glueful EmailNotification Extension',
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

    // Debug and development
    // debug.enabled: when true, beforeSend() logs each outgoing email's recipient/subject/type
    // at debug level (no payload values).
    'debug' => [
        'enabled' => env('MAIL_DEBUG', false),
    ],

    // After-send result logging
    // logging.enabled: when true, the provider's afterSend() hook logs the outcome of each email
    // (recipient, subject, notification type only -- no payload values) at info/error level.
    'logging' => [
        'enabled' => env('MAIL_LOG_RESULTS', false),
    ],

    // Security features
    // Recipient domain policy, enforced by EmailChannel before sending. EVERY recipient is
    // checked -- the primary recipient plus all cc/bcc addresses -- so an allowlist cannot be
    // bypassed via a cc/bcc field; any disallowed address fails the whole send closed.
    //   - blocked_domains: denylist (a matching recipient domain is rejected)
    //   - allowed_domains: allowlist (when set, only matching recipient domains pass)
    // Matching is ASYMMETRIC by design: blocked_domains also matches subdomains (blocking
    // 'evil.com' also blocks 'sub.evil.com'), while allowed_domains is EXACT-match only
    // (allowlisting 'company.com' does NOT permit 'sub.company.com'). Subdomain-widening the
    // allowlist would silently permit recipients beyond what was explicitly listed, so the
    // allowlist stays strict.
    // Each accepts a comma-separated string (env) or an array.
    //
    // Attachment path confinement, enforced by EmailChannel. Attachment
    // and embedded-image paths come from notification data (potentially user-influenced); they are
    // accepted only when realpath() resolves them INSIDE one of these base directories, so a caller
    // cannot attach arbitrary host files (e.g. /etc/passwd, .env, private keys) and exfiltrate them.
    //   - attachment_allowed_paths: array of allowed base directories. Each is normalized through
    //     realpath() and matched with a trailing separator so a sibling dir cannot pass for a child
    //     ('/app/storage-evil' does NOT satisfy base '/app/storage'). A rejected path is a
    //     non-retryable 'invalid_attachment' failure (never silently skipped). Default when null /
    //     empty: the application's storage directory (storage_path()).
    'security' => [
        'allowed_domains' => env('MAIL_ALLOWED_DOMAINS', null),
        'blocked_domains' => env('MAIL_BLOCKED_DOMAINS', null),
        // null/empty => confine to the application storage directory.
        'attachment_allowed_paths' => null,
    ],
];
