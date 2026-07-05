<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
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
        private readonly EmailSettings $settings,
        private readonly EmailChannel $channel
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
        $body = (array) json_decode((string) $request->getContent(), true);
        $to = is_string($body['to'] ?? null) ? trim($body['to']) : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('A valid `to` email address is required.', 422);
        }

        // A REAL send through the stored effective settings — the point of the
        // button is proving the transport works, not that the config is readable.
        $result = $this->channel->sendNotification(new TestRecipient($to), [
            'subject' => 'Test email',
            'html_content' => '<p>This is a test email confirming your email settings work.</p>',
            'text_content' => 'This is a test email confirming your email settings work.',
            'type' => 'email_settings_test',
        ]);

        if (!$result->success) {
            $status = $result->errorCode === 'transport_misconfigured' ? 422 : 502;
            return Response::error($result->errorMessage ?? 'Test send failed.', $status);
        }

        return Response::success(['sent_to' => $to], 'Test email sent.');
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
        if (isset($settings['mailers']) && is_array($settings['mailers'])) {
            foreach ($settings['mailers'] as $name => $mailer) {
                if (!is_array($mailer)) {
                    continue;
                }

                foreach (['password', 'key', 'secret', 'token', 'dsn'] as $secretKey) {
                    unset($mailer[$secretKey]);
                }
                $settings['mailers'][$name] = $mailer;
            }
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
