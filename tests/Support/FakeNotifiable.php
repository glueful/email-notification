<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests\Support;

use Glueful\Notifications\Contracts\Notifiable;

/**
 * Minimal Notifiable test double with a configurable email route.
 */
final class FakeNotifiable implements Notifiable
{
    public function __construct(private ?string $email)
    {
    }

    public function routeNotificationFor(string $channel)
    {
        return $channel === 'email' ? $this->email : null;
    }

    public function getNotifiableId(): string
    {
        return 'test-user';
    }

    public function getNotifiableType(): string
    {
        return 'user';
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return true;
    }

    public function getNotificationPreferences(): array
    {
        return [];
    }
}
