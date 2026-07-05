<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailSettingsTable;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\Settings\EmailSettings;
use Glueful\Extensions\EmailNotification\Settings\SettingsRepository;
use Glueful\Extensions\EmailNotification\Tests\Support\FakeNotifiable;
use PHPUnit\Framework\TestCase;

final class EmailSettingsTest extends TestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_KEY'] = self::APP_KEY;
        putenv('APP_KEY=' . self::APP_KEY);
        $_ENV['APP_PREVIOUS_KEYS'] = '';
        putenv('APP_PREVIOUS_KEYS=');
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        (new CreateEmailSettingsTable())->up($connection->getSchemaBuilder());

        return $connection;
    }

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir());
        $context->mergeConfigDefaults('encryption', [
            'key' => self::APP_KEY,
            'previous_keys' => [],
        ]);
        $context->mergeConfigDefaults('services', [
            'mail' => [
                'default' => 'smtp',
                'from' => ['address' => 'env@app.test', 'name' => 'Env App'],
                'bcc' => '',
                'logo_url' => 'https://example.com/logo.png',
                'mailers' => [
                    'smtp' => [
                        'transport' => 'smtp',
                        'host' => 'smtp.env.test',
                        'port' => 587,
                        'username' => 'env-user',
                        'password' => 'env-pass',
                        'encryption' => 'tls',
                    ],
                    'null' => ['transport' => 'null', 'dsn' => 'null://null'],
                ],
            ],
        ]);

        return $context;
    }

    private function repository(Connection $connection, ApplicationContext $context): SettingsRepository
    {
        return new SettingsRepository($connection, new EncryptionService($context));
    }

    public function test_effective_config_uses_db_rows_with_per_key_fallbacks(): void
    {
        $context = $this->context();
        $connection = $this->connection();
        $repository = $this->repository($connection, $context);
        $repository->set('host', 'smtp.db.test');
        $repository->set('port', '2525');
        $repository->set('username', '');
        $repository->set('from', 'db@app.test');

        $config = (new EmailSettings($context, $repository))->effectiveConfig();

        self::assertSame('smtp.db.test', $config['mailers']['smtp']['host']);
        self::assertSame(2525, $config['mailers']['smtp']['port']);
        self::assertSame('env-user', $config['mailers']['smtp']['username']);
        self::assertSame('db@app.test', $config['from']['address']);
        self::assertSame('Env App', $config['from']['name']);
    }

    public function test_password_is_ciphertext_at_rest_and_decrypted_only_in_effective_config(): void
    {
        $context = $this->context();
        $connection = $this->connection();
        $repository = $this->repository($connection, $context);

        $repository->set('password', 'db-secret');
        $raw = $repository->get('password');
        $config = (new EmailSettings($context, $repository))->effectiveConfig();

        self::assertIsString($raw);
        self::assertNotSame('db-secret', $raw);
        self::assertStringStartsWith('$glueful$v1$', $raw);
        self::assertSame('db-secret', $config['mailers']['smtp']['password']);
    }

    public function test_channel_uses_settings_saved_after_construction_on_next_send(): void
    {
        $context = $this->context();
        $connection = $this->connection();
        $repository = $this->repository($connection, $context);
        $settings = new EmailSettings($context, $repository);
        $channel = new EmailChannel($context, [], null, $settings);

        $repository->set('mailer', 'null');

        $result = $channel->sendNotification(new FakeNotifiable('user@app.test'), [
            'template_name' => 'default',
            'message' => 'Hello',
        ]);

        self::assertTrue($result->success, $result->errorMessage ?? '');
    }

    public function test_policy_survives_db_transport_settings_rewire(): void
    {
        $context = $this->context();
        $context->mergeConfigDefaults('emailnotification', [
            'security' => ['allowed_domains' => 'yourcompany.com'],
        ]);
        $connection = $this->connection();
        $repository = $this->repository($connection, $context);
        $repository->set('mailer', 'null');

        $channel = new EmailChannel($context, [], null, new EmailSettings($context, $repository));
        $result = $channel->sendNotification(new FakeNotifiable('user@other.com'), [
            'template_name' => 'default',
            'message' => 'Hello',
        ]);

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
    }
}
