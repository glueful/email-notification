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
}
