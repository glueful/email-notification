<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Extensions\EmailNotification\Templates\MustacheLiteEngine;
use PHPUnit\Framework\TestCase;

final class MustacheLiteEngineTest extends TestCase
{
    private string $partialsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partialsDir = sys_get_temp_dir() . '/email-partials-' . uniqid('', true);
        mkdir($this->partialsDir, 0777, true);
        file_put_contents($this->partialsDir . '/footer.html', '<footer>{{app.name}}</footer>');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->partialsDir)) {
            array_map('unlink', glob($this->partialsDir . '/*.html') ?: []);
            @rmdir($this->partialsDir);
        }
        parent::tearDown();
    }

    public function test_variables_are_escaped_and_raw_slots_are_not(): void
    {
        $engine = new MustacheLiteEngine([$this->partialsDir]);

        $rendered = $engine->render(
            '<p>{{name|Guest}}</p><main>{{{content}}}</main>',
            ['name' => '<Admin>', 'content' => '<strong>Safe internal HTML</strong>']
        );

        self::assertStringContainsString('&lt;Admin&gt;', $rendered);
        self::assertStringContainsString('<strong>Safe internal HTML</strong>', $rendered);
    }

    public function test_partials_conditionals_and_dot_notation_render_like_the_current_formatter(): void
    {
        $engine = new MustacheLiteEngine([$this->partialsDir]);

        $rendered = $engine->render(
            '{{#if show}}Hello {{user.name}}{{/if}}{{#if missing}}Hidden{{/if}}{{> footer}}',
            ['show' => true, 'user' => ['name' => 'Ada'], 'app' => ['name' => 'Glueful']]
        );

        self::assertSame('Hello Ada<footer>Glueful</footer>', $rendered);
    }

    public function test_missing_partials_render_the_existing_comment_shape(): void
    {
        $engine = new MustacheLiteEngine([$this->partialsDir]);

        self::assertSame(
            '<!-- Partial not found: missing -->',
            $engine->render('{{> missing}}', [])
        );
    }

    public function test_violations_report_unbalanced_conditionals(): void
    {
        $engine = new MustacheLiteEngine([$this->partialsDir]);

        self::assertSame([], $engine->violations('{{#if ok}}Hi{{/if}}'));
        self::assertSame(['Unclosed conditional block: {{#if ok}}'], $engine->violations('{{#if ok}}Hi'));
        self::assertSame(['Unexpected conditional close: {{/if}}'], $engine->violations('Hi{{/if}}'));
    }
}
