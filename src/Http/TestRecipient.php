<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Notifications\Contracts\Notifiable;

/**
 * Ad-hoc notifiable for admin test-sends: routes the email channel to the
 * operator-supplied address and nothing else. The domain policy still
 * applies — a test-send to a blocked/non-allowlisted address fails closed
 * like any other send.
 */
final class TestRecipient implements Notifiable
{
    public function __construct(private readonly string $email)
    {
    }

    public function getNotifiableId(): string
    {
        return 'email-test';
    }

    public function getNotifiableType(): string
    {
        return 'system';
    }

    public function routeNotificationFor(string $channel): ?string
    {
        return $channel === 'email' ? $this->email : null;
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function getNotificationPreferences(): array
    {
        return [];
    }
}
