<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Settings;

use Glueful\Bootstrap\ApplicationContext;

final class EmailSettings
{
    /**
     * What each provider's bridge can send through, in the order the admin offers them. A mailer
     * the list does not name sends through whatever its config's `transport` says, alone.
     *
     * @var array<string, list<string>>
     */
    private const TRANSPORTS = [
        'smtp' => ['smtp'],
        'brevo' => ['brevo+api', 'brevo+smtp'],
        'sendgrid' => ['sendgrid+api'],
        'mailgun' => ['mailgun+api'],
        'ses' => ['ses+api'],
        'postmark' => ['postmark+api'],
    ];

    /**
     * The settings a transport actually reads. An `+api` bridge takes a key from the environment
     * and no host at all, which is why a form built for SMTP shows empty boxes against one.
     *
     * @var array<string, list<string>>
     */
    private const FIELDS = [
        'smtp' => ['host', 'port', 'encryption', 'username', 'password'],
        'brevo+smtp' => ['username', 'password'],
    ];

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

        // The chosen mailer may offer more than one way out (Brevo: its API, or its SMTP relay).
        // A stored transport applies only to the mailer that offers it; anything else falls back
        // to whatever the mailer's own config sends through.
        $chosen = is_array($mailers[$mailer] ?? null) ? $mailers[$mailer] : [];
        $transport = $this->value('transport', '');
        if ($transport === '' || !in_array($transport, self::transportsFor($mailer, $mailers), true)) {
            $transport = (string) ($chosen['transport'] ?? '');
        }
        if ($transport !== '') {
            // Whichever way out it is, the settings that transport reads come from the admin —
            // credentials shown against brevo+smtp have to reach the brevo mailer.
            $config['mailers'][$mailer] = $this->withStoredFields(
                array_replace($chosen, ['transport' => $transport]),
                $transport,
            );
            $mailers = $config['mailers'];
        }

        $smtpBase = is_array($mailers['smtp'] ?? null) ? $mailers['smtp'] : [];
        $config['mailers'] = $mailers;
        $config['mailers']['smtp'] = $this->withStoredFields(
            array_replace($smtpBase, ['transport' => $smtpBase['transport'] ?? 'smtp']),
            'smtp',
        );

        return $config;
    }

    /**
     * Overlay the stored values for the settings this transport actually reads. A setting the
     * transport ignores is left alone — an API bridge gains no host from a form built for SMTP.
     *
     * @param array<string, mixed> $mailer
     * @return array<string, mixed>
     */
    private function withStoredFields(array $mailer, string $transport): array
    {
        foreach (self::FIELDS[$transport] ?? [] as $field) {
            $mailer[$field] = match ($field) {
                'port' => (int) $this->value('port', (string) ($mailer['port'] ?? 587)),
                'password' => $this->password((string) ($mailer['password'] ?? '')),
                default => $this->value($field, (string) ($mailer[$field] ?? '')),
            };
        }

        return $mailer;
    }

    /**
     * What the admin needs to show the right boxes: per mailer, the transports it offers, the
     * settings each of those reads, and whether a keyed transport has its key.
     *
     * @return array<string, array{transports: list<string>, fields: list<string>,
     *     fields_by_transport: array<string, list<string>>, key_set: bool}>
     */
    public function capabilities(): array
    {
        $base = config($this->context, 'services.mail', []);
        $mailers = is_array($base) && is_array($base['mailers'] ?? null) ? $base['mailers'] : [];
        $config = $this->effectiveConfig();
        $out = [];
        foreach (array_keys(array_merge(['smtp' => []], $mailers)) as $name) {
            $name = (string) $name;
            $transports = self::transportsFor($name, $mailers);
            $current = (string) ($config['mailers'][$name]['transport'] ?? ($transports[0] ?? 'smtp'));
            $byTransport = [];
            foreach ($transports as $one) {
                $byTransport[$one] = self::FIELDS[$one] ?? [];
            }
            $out[$name] = [
                'transports' => $transports,
                'fields' => $byTransport[$current] ?? [],
                'fields_by_transport' => $byTransport,
                'key_set' => ((string) ($mailers[$name]['key'] ?? '')) !== ''
                    || ((string) ($mailers[$name]['dsn'] ?? '')) !== '',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $mailers
     * @return list<string>
     */
    private static function transportsFor(string $mailer, array $mailers): array
    {
        if (isset(self::TRANSPORTS[$mailer])) {
            return self::TRANSPORTS[$mailer];
        }
        $declared = is_array($mailers[$mailer] ?? null) ? (string) ($mailers[$mailer]['transport'] ?? '') : '';

        return $declared === '' ? [] : [$declared];
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
