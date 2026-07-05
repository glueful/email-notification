<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\EmailNotification\Database\Migrations\CreateEmailTemplatesTable;
use Glueful\Extensions\EmailNotification\Templates\DefinitionRegistry;
use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
use Glueful\Extensions\EmailNotification\Templates\OverrideRepository;
use Glueful\Extensions\EmailNotification\Templates\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private function connection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    private function registry(): DefinitionRegistry
    {
        $registry = new DefinitionRegistry();
        $registry->register(new EmailTemplateDefinition(
            key: 'demo',
            label: 'Demo',
            description: 'Demo template.',
            defaultSubject: 'Default {{name}}',
            defaultBody: '<p>Default {{name}}</p>',
            placeholders: [],
            owner: 'test'
        ));

        return $registry;
    }

    private function renderer(Connection $connection): TemplateRenderer
    {
        return new TemplateRenderer(
            $this->registry(),
            new OverrideRepository($connection),
            new MustacheLiteEngine([__DIR__ . '/../src/Templates/html/partials'])
        );
    }

    public function test_unknown_template_key_throws(): void
    {
        $connection = $this->connection();
        (new CreateEmailTemplatesTable())->up($connection->getSchemaBuilder());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown email template 'missing'");

        $this->renderer($connection)->render('missing', []);
    }

    public function test_subjects_interpolate_raw_never_html_escaped(): void
    {
        // Review fix: a subject header is not HTML — 'Q&A Hub' must not
        // arrive as 'Q&amp;A Hub'. Bodies keep escaping.
        $connection = $this->connection();
        (new CreateEmailTemplatesTable())->up($connection->getSchemaBuilder());

        $rendered = $this->renderer($connection)->render('demo', ['name' => 'Q&A Hub']);

        self::assertSame('Default Q&A Hub', $rendered['subject']);
        self::assertStringContainsString('Q&amp;A Hub', $rendered['html']); // body still escapes
    }

    public function test_override_beats_default_and_subject_is_template_owned(): void
    {
        $connection = $this->connection();
        (new CreateEmailTemplatesTable())->up($connection->getSchemaBuilder());
        $overrides = new OverrideRepository($connection);
        $overrides->save('demo', 'Override {{name}}', '<p>Override {{name}}</p>', null);

        $rendered = $this->renderer($connection)->render('demo', [
            'name' => 'Ada',
            'subject' => 'Caller supplied subject',
        ]);

        self::assertSame('Override Ada', $rendered['subject']);
        self::assertStringContainsString('<p>Override Ada</p>', $rendered['html']);
        self::assertStringContainsString('<div class="content">', $rendered['html']);
        self::assertStringNotContainsString('Caller supplied subject', $rendered['html']);
    }

    public function test_default_definition_renders_when_no_override_exists(): void
    {
        $connection = $this->connection();
        (new CreateEmailTemplatesTable())->up($connection->getSchemaBuilder());

        $rendered = $this->renderer($connection)->render('demo', ['name' => 'Ada']);

        self::assertSame('Default Ada', $rendered['subject']);
        self::assertStringContainsString('<p>Default Ada</p>', $rendered['html']);
    }
}
