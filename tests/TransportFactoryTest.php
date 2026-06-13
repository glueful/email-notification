<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Extensions\EmailNotification\TransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * TransportFactory builds a Symfony transport from the mailer config (no network/send).
 */
final class TransportFactoryTest extends TestCase
{
    public function test_builds_smtp_transport_from_config(): void
    {
        $transport = TransportFactory::create([
            'default' => 'smtp',
            'mailers' => [
                'smtp' => ['transport' => 'smtp', 'host' => 'localhost', 'port' => 1025],
            ],
        ]);

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function test_honors_a_dsn_override(): void
    {
        $transport = TransportFactory::create([
            'default' => 'custom',
            'mailers' => ['custom' => ['dsn' => 'null://null']],
        ]);

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function test_unknown_mailer_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::create(['default' => 'nope', 'mailers' => []]);
    }

    public function test_available_providers_include_builtins(): void
    {
        $providers = TransportFactory::getAvailableProviders();

        self::assertArrayHasKey('smtp', $providers);
        self::assertArrayHasKey('null', $providers);
    }

    public function test_invalid_smtp_host_with_smuggled_authority_throws(): void
    {
        // "smtp.legit.com@evil.com" would split the DSN authority and redirect mail; reject it.
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::create([
            'default' => 'smtp',
            'mailers' => ['smtp' => ['transport' => 'smtp', 'host' => 'smtp.legit.com@evil.com']],
        ]);
    }

    public function test_ipv6_bracketed_host_is_accepted(): void
    {
        $transport = TransportFactory::create([
            'default' => 'smtp',
            'mailers' => ['smtp' => ['transport' => 'smtp', 'host' => '[::1]', 'port' => 1025]],
        ]);

        self::assertInstanceOf(TransportInterface::class, $transport);
    }
}
