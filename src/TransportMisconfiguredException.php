<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

/**
 * Raised when the mail transport cannot be built from the supplied configuration.
 *
 * This is a permanent (non-retryable) condition -- a missing SMTP host, missing provider-bridge
 * credentials, an invalid host, or a broken failover chain. {@see EmailChannel::sendNotification()}
 * catches it and returns a `transport_misconfigured` failure instead of silently routing mail to
 * the null transport.
 *
 * @package Glueful\Extensions\EmailNotification
 */
final class TransportMisconfiguredException extends \RuntimeException
{
}
