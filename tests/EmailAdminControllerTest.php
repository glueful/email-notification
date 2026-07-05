<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailSettingsTable;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailTemplatesTable;
use Glueful\Extensions\EmailNotification\EmailFormatter;
use Glueful\Extensions\EmailNotification\Tests\Support\CapturingEmailChannel;
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
    private CapturingEmailChannel $channel;

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
        // Overrides shared with the engine so partial.{name} rows apply (prod wiring).
        $engine = new MustacheLiteEngine([__DIR__ . '/../src/Templates/html/partials'], '.html', $overrides);
        $renderer = new TemplateRenderer($registry, $overrides, $engine);
        $settingsRepository = new SettingsRepository($connection, new EncryptionService($context));
        // Capturing channel WITH the harness renderer: test-sends exercise the
        // REAL send path (formatter -> renderer incl. DB overrides -> policy ->
        // transport), and tests assert the message content that reached the
        // transport.
        $this->channel = new CapturingEmailChannel($context, [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null', 'dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ], new EmailFormatter($context, [], [], $renderer));

        return [
            new TemplatesController($registry, $overrides, $engine, $renderer, $this->channel),
            new SettingsController(
                $context,
                $settingsRepository,
                new EmailSettings($context, $settingsRepository),
                $this->channel,
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

        // A REAL send: the transport receives THIS template rendered with its
        // placeholder SAMPLES (not the default template, not pre-rendered noise).
        $ok = $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'operator@app.test']),
            'verification'
        );
        self::assertSame(200, $ok->getStatusCode());
        $data = $this->json($ok)['data'];
        self::assertSame('operator@app.test', $data['sent_to']);

        self::assertCount(1, $this->channel->sent);
        $message = $this->channel->sent[0];
        self::assertSame('Verify your Glueful email', $message->getSubject()); // sample app_name
        self::assertStringContainsString('123456', (string) $message->getHtmlBody()); // sample otp
        self::assertSame('operator@app.test', $message->getTo()[0]->getAddress());

        // The button's claim is "test MY template": a saved override is what sends.
        $templates->save(
            $this->jsonRequest('PUT', '/email/templates/verification', [
                'subject' => 'Custom verify {{app_name}}',
                'body' => '<p>Code {{otp}}</p>',
            ]),
            'verification'
        );
        $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'operator@app.test']),
            'verification'
        );
        self::assertCount(2, $this->channel->sent);
        self::assertSame('Custom verify Glueful', $this->channel->sent[1]->getSubject());
        self::assertStringContainsString('Code 123456', (string) $this->channel->sent[1]->getHtmlBody());
    }

    public function test_settings_test_send_actually_sends_and_validates_to(): void
    {
        [, $settings] = $this->controllers();

        $bad = $settings->testSend($this->jsonRequest('POST', '/email/settings/test', []));
        self::assertSame(422, $bad->getStatusCode());

        $ok = $settings->testSend($this->jsonRequest('POST', '/email/settings/test', ['to' => 'operator@app.test']));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('operator@app.test', $this->json($ok)['data']['sent_to']);

        // The transport receives the actual plain test message.
        self::assertCount(1, $this->channel->sent);
        self::assertStringContainsString(
            'confirming your email settings work',
            (string) $this->channel->sent[0]->getHtmlBody(),
        );
    }

    public function test_partials_list_save_reset_and_render_through_overrides(): void
    {
        [$templates] = $this->controllers();

        // Listed alongside templates with effective bodies + language metadata.
        $data = $this->json($templates->index($this->jsonRequest('GET', '/email/templates', [])))['data'];
        $partials = array_column($data['partials'], null, 'key');
        self::assertArrayHasKey('partial.layout', $partials);
        self::assertArrayHasKey('partial.styles', $partials);
        self::assertSame('css', $partials['partial.styles']['language']);
        self::assertFalse($partials['partial.styles']['overridden']);
        self::assertStringContainsString('.otp-code', $partials['partial.styles']['body']); // shipped CSS

        // Override the styles partial (the CSS-injection point) and send: the
        // transport-received HTML carries the custom CSS.
        $save = $templates->save(
            $this->jsonRequest('PUT', '/email/templates/partial.styles', ['body' => '.brand { color: teal; }']),
            'partial.styles'
        );
        self::assertSame(200, $save->getStatusCode());
        $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'operator@app.test']),
            'verification'
        );
        self::assertStringContainsString('.brand { color: teal; }', (string) $this->channel->sent[0]->getHtmlBody());

        // Layout override wraps the send too.
        $templates->save(
            $this->jsonRequest('PUT', '/email/templates/partial.layout', [
                'body' => '<!DOCTYPE html><html><body id="custom-layout">{{{content}}}</body></html>',
            ]),
            'partial.layout'
        );
        $templates->testSend(
            $this->jsonRequest('POST', '/email/templates/verification/test', ['to' => 'operator@app.test']),
            'verification'
        );
        self::assertStringContainsString('id="custom-layout"', (string) $this->channel->sent[1]->getHtmlBody());

        // Reset restores the shipped file.
        self::assertSame(200, $templates->reset($this->jsonRequest('DELETE', '/email/templates/partial.styles', []), 'partial.styles')->getStatusCode());
        $data = $this->json($templates->index($this->jsonRequest('GET', '/email/templates', [])))['data'];
        $styles = array_column($data['partials'], null, 'key')['partial.styles'];
        self::assertFalse($styles['overridden']);
        self::assertStringContainsString('.otp-code', $styles['body']);

        // Unknown partial keys stay 404; empty body 422s.
        self::assertSame(404, $templates->save($this->jsonRequest('PUT', '/x', ['body' => 'x']), 'partial.nope')->getStatusCode());
        self::assertSame(422, $templates->save($this->jsonRequest('PUT', '/x', ['body' => '  ']), 'partial.header')->getStatusCode());
    }
}
