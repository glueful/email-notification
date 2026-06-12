<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\EmailNotificationProvider;
use Glueful\Notifications\Services\ChannelManager;
use PHPUnit\Framework\TestCase;

/**
 * EmailNotificationProvider must wire the shared, DI-built EmailChannel (never a second one)
 * and validate provider credentials against the REAL transport strings.
 */
final class EmailNotificationProviderTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function channel(array $config): EmailChannel
    {
        return new EmailChannel($this->context(), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provider(EmailChannel $channel, array $config): EmailNotificationProvider
    {
        // Pass config explicitly so the provider's hook/diagnostics copy matches the channel's;
        // ApplicationContext(sys_get_temp_dir()) loads no config files, so injection is the only
        // source here.
        return new EmailNotificationProvider($this->context(), $channel, $config);
    }

    public function test_register_uses_the_injected_channel_instance(): void
    {
        $config = [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null', 'dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ];

        $channel = $this->channel($config);
        $provider = $this->provider($channel, $config);

        $manager = new ChannelManager();
        $provider->register($manager);

        self::assertTrue($manager->hasChannel('email'));
        // The provider must register the SAME shared instance, not build a second channel.
        self::assertSame($channel, $manager->getChannel('email'));
    }

    public function test_configured_returns_false_for_sendgrid_api_without_key(): void
    {
        $config = [
            'default' => 'sendgrid',
            'mailers' => ['sendgrid' => ['transport' => 'sendgrid+api']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ];

        $channel = $this->channel($config);
        $provider = $this->provider($channel, $config);

        self::assertFalse($provider->isEmailProviderConfigured());
    }

    public function test_configured_returns_true_for_sendgrid_api_with_key(): void
    {
        $config = [
            'default' => 'sendgrid',
            'mailers' => ['sendgrid' => ['transport' => 'sendgrid+api', 'key' => 'SG.test-key']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ];

        $channel = $this->channel($config);
        $provider = $this->provider($channel, $config);

        // isEmailProviderConfigured() also requires the (shared) channel to be initialized and
        // available -- initialize() now just adopts the injected channel.
        self::assertTrue($provider->initialize());
        self::assertTrue($provider->isEmailProviderConfigured());
    }

    public function test_configured_returns_false_for_unknown_transport(): void
    {
        $config = [
            'default' => 'mystery',
            'mailers' => ['mystery' => ['transport' => 'totally-unknown', 'key' => 'x']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ];

        $channel = $this->channel($config);
        $provider = $this->provider($channel, $config);

        self::assertFalse($provider->isEmailProviderConfigured());
    }

    /**
     * getExtensionInfo() is a diagnostic surface; it must never echo credential VALUES from the
     * merged mail config. Seed the config with sentinel credentials and assert none of them appear
     * anywhere in the (json-encoded) output, while safe metadata is still surfaced.
     */
    public function test_get_extension_info_omits_credential_values(): void
    {
        $config = [
            'default' => 'sendgrid',
            'mailers' => [
                'sendgrid' => [
                    'transport' => 'sendgrid+api',
                    'key' => 'SENTINEL-API-KEY-9f3a',
                    'username' => 'SENTINEL-USERNAME',
                    'password' => 'SENTINEL-PASSWORD',
                    'secret' => 'SENTINEL-SECRET',
                    'token' => 'SENTINEL-TOKEN',
                    'dsn' => 'sendgrid+api://SENTINEL-API-KEY-9f3a@default',
                ],
            ],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
            'security' => ['allowed_domains' => ['app.test']],
            'debug' => ['enabled' => true],
        ];

        $channel = $this->channel($config);
        $provider = $this->provider($channel, $config);

        $info = $provider->getExtensionInfo();
        $encoded = json_encode($info);
        self::assertIsString($encoded);

        // No sentinel credential string may survive into the diagnostic output.
        foreach (
            [
                'SENTINEL-API-KEY-9f3a',
                'SENTINEL-USERNAME',
                'SENTINEL-PASSWORD',
                'SENTINEL-SECRET',
                'SENTINEL-TOKEN',
            ] as $sentinel
        ) {
            self::assertStringNotContainsString($sentinel, $encoded);
        }

        // Safe metadata is still surfaced.
        self::assertSame('sendgrid', $info['config']['default_mailer']);
        self::assertSame('sendgrid+api', $info['config']['transport']);
        self::assertSame('noreply@app.test', $info['config']['from_address']);
        self::assertTrue($info['config']['features']['debug_enabled']);
        self::assertTrue($info['config']['features']['domain_policy_configured']);
    }

    /**
     * The TransportExceptionInterface catch in sendNotification() previously logged the entire
     * notification payload (OTP pins, reset tokens/URLs, PII). The extracted
     * safeNotificationLogContext() helper is the single source of the safe log fragment: it must
     * surface only operator-authored/identifier fields plus the payload KEYS, never the values.
     */
    public function test_safe_notification_log_context_excludes_payload_values(): void
    {
        $config = [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null', 'dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ];
        $channel = $this->channel($config);

        $data = [
            'subject' => 'Reset your password',
            'type' => 'password_reset',
            'template_name' => 'password-reset',
            'otp' => '123456',
            'reset_token' => 'super-secret-reset-token',
            'reset_url' => 'https://app.test/reset?token=super-secret-reset-token',
            'recipient_name' => 'Jane Doe',
        ];

        $method = new \ReflectionMethod($channel, 'safeNotificationLogContext');
        $method->setAccessible(true);
        /** @var array<string, mixed> $context */
        $context = $method->invoke($channel, $data);

        $encoded = json_encode($context);
        self::assertIsString($encoded);

        // Sensitive VALUES must never appear in the log context.
        self::assertStringNotContainsString('123456', $encoded);
        self::assertStringNotContainsString('super-secret-reset-token', $encoded);
        self::assertStringNotContainsString('Jane Doe', $encoded);

        // Whitelisted, non-sensitive fields are echoed verbatim.
        self::assertSame('Reset your password', $context['subject']);
        self::assertSame('password_reset', $context['type']);
        self::assertSame('password-reset', $context['template_name']);

        // The payload KEYS are still recorded so the shape stays auditable.
        self::assertContains('otp', $context['notification_keys']);
        self::assertContains('reset_token', $context['notification_keys']);
        self::assertContains('reset_url', $context['notification_keys']);
    }
}
