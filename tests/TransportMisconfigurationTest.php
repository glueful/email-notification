<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\Tests\Support\FakeNotifiable;
use PHPUnit\Framework\TestCase;

/**
 * A misconfigured mail transport must fail loudly. Previously EmailChannel silently fell back to
 * the null transport, so sendNotification() reported success while every message was discarded.
 * These tests pin that misconfiguration now yields a non-retryable `transport_misconfigured`
 * failure, while an explicitly-configured null transport still sends.
 */
final class TransportMisconfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function channel(array $config): EmailChannel
    {
        $config['from'] ??= ['address' => 'noreply@app.test', 'name' => 'App'];

        return new EmailChannel(new ApplicationContext(sys_get_temp_dir()), $config);
    }

    public function test_missing_smtp_host_fails_not_silently_succeeds(): void
    {
        $result = $this->channel([
            'default' => 'smtp',
            'mailers' => ['smtp' => ['transport' => 'smtp']], // no host
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertFalse($result->success, 'a missing SMTP host must not report success');
        self::assertSame('transport_misconfigured', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_provider_bridge_without_credentials_fails(): void
    {
        $result = $this->channel([
            'default' => 'brevo',
            'mailers' => ['brevo' => ['transport' => 'brevo+api']], // no key/credentials
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('transport_misconfigured', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_explicit_null_transport_still_sends(): void
    {
        // An intentional null sink (transport: 'null') is a supported configuration and must
        // keep working -- only the implicit fallback was removed.
        $result = $this->channel([
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null']],
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertTrue($result->success, 'an explicit null transport should still send');
    }

    public function test_null_dsn_mailer_still_sends(): void
    {
        $result = $this->channel([
            'default' => 'null',
            'mailers' => ['null' => ['dsn' => 'null://null']],
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertTrue($result->success);
    }

    public function test_unknown_default_mailer_fails_as_misconfigured(): void
    {
        $result = $this->channel([
            'default' => 'does-not-exist',
            'mailers' => [],
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('transport_misconfigured', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_invalid_host_propagates_as_misconfigured(): void
    {
        // A smuggled second authority ("@evil.com") is rejected by the factory; the channel maps
        // that factory failure to a non-retryable transport_misconfigured result.
        $result = $this->channel([
            'default' => 'smtp',
            'mailers' => ['smtp' => ['transport' => 'smtp', 'host' => 'smtp.legit.com@evil.com']],
        ])->sendNotification(new FakeNotifiable('user@app.test'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('transport_misconfigured', $result->errorCode);
        self::assertFalse($result->retryable);
    }
}
