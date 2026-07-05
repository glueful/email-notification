<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Settings;

use Glueful\Bootstrap\ApplicationContext;

final class EmailSettings
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SettingsRepository $repository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function effectiveConfig(): array
    {
        $base = config($this->context, 'services.mail', []);
        $base = is_array($base) ? $base : [];
        $mailers = is_array($base['mailers'] ?? null) ? $base['mailers'] : [];
        $validMailers = array_values(array_unique(array_merge(['smtp'], array_keys($mailers))));

        $mailer = $this->value('mailer', (string) ($base['default'] ?? 'smtp'));
        if (!in_array($mailer, $validMailers, true)) {
            error_log("email-notification: stored mailer '{$mailer}' is not configured; falling back.");
            $mailer = (string) ($base['default'] ?? 'smtp');
        }

        $config = $base;
        $config['default'] = $mailer;
        $config['from'] = [
            'address' => $this->value('from', (string) ($base['from']['address'] ?? '')),
            'name' => $this->value('from_name', (string) ($base['from']['name'] ?? '')),
        ];
        $config['bcc'] = $this->value('bcc', (string) ($base['bcc'] ?? ''));
        $config['logo_url'] = $this->value('logo_url', (string) ($base['logo_url'] ?? ''));

        $smtpBase = is_array($mailers['smtp'] ?? null) ? $mailers['smtp'] : [];
        $config['mailers'] = $mailers;
        $config['mailers']['smtp'] = array_replace($smtpBase, [
            'transport' => $smtpBase['transport'] ?? 'smtp',
            'host' => $this->value('host', (string) ($smtpBase['host'] ?? '')),
            'port' => (int) $this->value('port', (string) ($smtpBase['port'] ?? 587)),
            'username' => $this->value('username', (string) ($smtpBase['username'] ?? '')),
            'password' => $this->password((string) ($smtpBase['password'] ?? '')),
            'encryption' => $this->value('encryption', (string) ($smtpBase['encryption'] ?? '')),
        ]);

        return $config;
    }

    private function value(string $key, string $fallback): string
    {
        $value = $this->repository->get($key);

        return $value === null || $value === '' ? $fallback : $value;
    }

    private function password(string $fallback): string
    {
        $value = $this->repository->get('password');
        if ($value === null || $value === '') {
            return $fallback;
        }

        return $this->repository->decryptPassword($value);
    }
}
