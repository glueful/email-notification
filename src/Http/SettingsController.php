<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\Settings\EmailSettings;
use Glueful\Extensions\EmailNotification\Settings\SettingsRepository;
use Glueful\Helpers\RequestHelper;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class SettingsController
{
    private const KEYS = [
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from',
        'from_name',
        'bcc',
        'logo_url',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SettingsRepository $repository,
        private readonly EmailSettings $settings
    ) {
    }

    public function show(Request $request): Response
    {
        return Response::success($this->payload(), 'Email settings retrieved.');
    }

    public function save(Request $request): Response
    {
        $data = RequestHelper::getRequestData($request);
        $errors = [];

        if (isset($data['mailer']) && is_string($data['mailer']) && !$this->isAllowedMailer($data['mailer'])) {
            $errors['mailer'] = 'Mailer is not configured.';
        }
        if (isset($data['port']) && $data['port'] !== '' && !is_numeric($data['port'])) {
            $errors['port'] = 'Port must be numeric.';
        }
        if ($errors !== []) {
            return Response::validation($errors);
        }

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null) {
                $this->repository->set($key, '');
                continue;
            }

            $this->repository->set($key, (string) $value);
        }

        return Response::success($this->payload(), 'Email settings saved.');
    }

    public function testSend(Request $request): Response
    {
        return Response::success(['settings' => $this->redactedSettings()], 'Email settings are readable.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'settings' => $this->redactedSettings(),
            'password_set' => ($this->repository->get('password') ?? '') !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function redactedSettings(): array
    {
        $settings = $this->settings->effectiveConfig();
        if (isset($settings['mailers']['smtp']) && is_array($settings['mailers']['smtp'])) {
            unset($settings['mailers']['smtp']['password']);
        }

        return $settings;
    }

    private function isAllowedMailer(string $mailer): bool
    {
        $base = config($this->context, 'services.mail.mailers', []);
        $mailers = is_array($base) ? array_keys($base) : [];

        return in_array($mailer, array_values(array_unique(array_merge(['smtp'], $mailers))), true);
    }
}
