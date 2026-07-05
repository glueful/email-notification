<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailSettingsTable;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailTemplatesTable;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\Http\SettingsController;
use Glueful\Extensions\EmailNotification\Http\TemplatesController;
use Glueful\Extensions\EmailNotification\Settings\EmailSettings;
use Glueful\Extensions\EmailNotification\Settings\SettingsRepository;
use Glueful\Extensions\EmailNotification\Templates\BuiltInDefinitions;
use Glueful\Extensions\EmailNotification\Templates\DefinitionRegistry;
use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
use Glueful\Extensions\EmailNotification\Templates\OverrideRepository;
use Glueful\Extensions\EmailNotification\Templates\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EmailAdminControllerTest extends TestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir());
        $context->mergeConfigDefaults('encryption', ['key' => self::APP_KEY, 'previous_keys' => []]);
        $context->mergeConfigDefaults('services', [
            'mail' => [
                'default' => 'smtp',
                'from' => ['address' => 'env@app.test', 'name' => 'Env App'],
                'mailers' => [
                    'smtp' => ['transport' => 'smtp', 'host' => 'smtp.env.test', 'port' => 587],
                    'null' => ['transport' => 'null', 'dsn' => 'null://null'],
                    'api' => ['transport' => 'api', 'key' => 'env-api-key'],
                ],
            ],
        ]);

        return $context;
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $schema = $connection->getSchemaBuilder();
        (new CreateEmailTemplatesTable())->up($schema);
        (new CreateEmailSettingsTable())->up($schema);

        return $connection;
    }

    /**
     * @return array{TemplatesController,SettingsController,SettingsRepository}
     */
    private function controllers(): array
    {
        $context = $this->context();
        $connection = $this->connection();
        $registry = new DefinitionRegistry();
        $registry->register(...BuiltInDefinitions::all());
        $overrides = new OverrideRepository($connection);
        $engine = new MustacheLiteEngine([__DIR__ . '/../src/Templates/html/partials']);
        $renderer = new TemplateRenderer($registry, $overrides, $engine);
        $settingsRepository = new SettingsRepository($connection, new EncryptionService($context));
        // Null-transport channel: test-sends exercise the REAL send path
        // (policy + transport) without touching a network.
        $channel = new EmailChannel($context, [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null', 'dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ]);

        return [
            new TemplatesController($registry, $overrides, $engine, $renderer, $channel),
            new SettingsController(
                $context,
                $settingsRepository,
                new EmailSettings($context, $settingsRepository),
                $channel,
            ),
            $settingsRepository,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $method, string $path, array $payload): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload) ?: ''
        );
    }

    public function test_templates_list_and_save_reset_lifecycle(): void
    {
        [$templates] = $this->controllers();

        $list = $this->json($templates->index(new Request()));
        self::assertTrue($list['success']);
        self::assertNotEmpty($list['data']['templates']);

        $save = $templates->save($this->jsonRequest('PUT', '/email/templates/welcome', [
            'subject' => 'Custom {{name}}',
            'body' => '<p>Hello {{name}}</p>',
        ]), 'welcome');
        self::assertSame(200, $save->getStatusCode());

        $afterSave = $this->json($templates->index(new Request()));
        $welcome = array_values(array_filter(
            $afterSave['data']['templates'],
            static fn (array $row): bool => $row['key'] === 'welcome'
        ))[0];
        self::assertTrue($welcome['overridden']);
        self::assertSame('Custom {{name}}', $welcome['subject']);

        $reset = $templates->reset(new Request(), 'welcome');
        self::assertSame(200, $reset->getStatusCode());
    }

    public function test_template_save_rejects_unknown_key_and_unbalanced_conditionals(): void
    {
        [$templates] = $this->controllers();

        self::assertSame(404, $templates->save(new Request(), 'missing')->getStatusCode());

        $response = $templates->save($this->jsonRequest('PUT', '/email/templates/welcome', [
            'subject' => 'Hi',
            'body' => '{{#if name}}Hello',
        ]), 'welcome');

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_settings_round_trip_never_returns_password(): void
    {
        [, $settings, $repository] = $this->controllers();

        $save = $settings->save($this->jsonRequest('PUT', '/email/settings', [
            'mailer' => 'null',
            'host' => 'smtp.db.test',
            'password' => 'secret',
        ]));
        self::assertSame(200, $save->getStatusCode());

        $show = $this->json($settings->show(new Request()));

        self::assertSame('null', $show['data']['settings']['default']);
        self::assertSame('smtp.db.test', $show['data']['settings']['mailers']['smtp']['host']);
        self::assertTrue($show['data']['password_set']);
        self::assertArrayNotHasKey('password', $show['data']['settings']['mailers']['smtp']);
        self::assertArrayNotHasKey('key', $show['data']['settings']['mailers']['api']);
        self::assertArrayNotHasKey('dsn', $show['data']['settings']['mailers']['null']);
        self::assertNotSame('secret', $repository->get('password'));
    }

    public function test_template_test_send_actually_sends_and_validates_to(): void
    {
        [$templates] = $this->controllers();

        // Missing/invalid address -> 422, nothing sent.
        $bad = $templates->testSend($this->jsonRequest('POST', '/email/templates/verification/test', []), 'verification');
        self::assertSame(422, $bad->getStatusCode());
        $bad = $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'not-an-email']),
            'verification'
        );
        self::assertSame(422, $bad->getStatusCode());

        // A REAL send through the (null) transport: success + sent_to echoed.
        $ok = $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'operator@app.test']),
            'verification'
        );
        self::assertSame(200, $ok->getStatusCode());
        $data = $this->json($ok)['data'];
        self::assertSame('operator@app.test', $data['sent_to']);
        self::assertNotSame('', (string) $data['subject']); // sample-rendered subject travels back
    }

    public function test_settings_test_send_actually_sends_and_validates_to(): void
    {
        [, $settings] = $this->controllers();

        $bad = $settings->testSend($this->jsonRequest('POST', '/email/settings/test', []));
        self::assertSame(422, $bad->getStatusCode());

        $ok = $settings->testSend($this->jsonRequest('POST', '/email/settings/test', ['to' => 'operator@app.test']));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('operator@app.test', $this->json($ok)['data']['sent_to']);
    }
}
