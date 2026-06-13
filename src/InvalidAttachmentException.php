<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

/**
 * Raised when an attachment or embedded-image path is not confined to an allowed base directory.
 *
 * This is a permanent (non-retryable) condition -- a notification asked to attach/embed a file
 * that resolves outside the configured `security.attachment_allowed_paths` allowlist (or whose
 * path cannot be resolved at all). {@see EmailChannel::sendNotification()} catches it and returns
 * an `invalid_attachment` failure instead of letting arbitrary host files (e.g. /etc/passwd, .env,
 * private keys) be exfiltrated to a chosen recipient.
 *
 * Mirrors {@see TransportMisconfiguredException}: a dedicated type that sendNotification() funnels
 * into a structured failure result.
 *
 * @package Glueful\Extensions\EmailNotification
 */
final class InvalidAttachmentException extends \RuntimeException
{
    /**
     * The rejected path exactly as supplied by the notification data (for logging). This is the
     * caller-provided value, not a resolved realpath -- realpath() may have returned false.
     */
    public function __construct(public readonly string $rejectedPath, string $message)
    {
        parent::__construct($message);
    }
}
