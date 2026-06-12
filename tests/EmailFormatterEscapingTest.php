<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailFormatter;
use Glueful\Extensions\EmailNotification\Tests\Support\FakeNotifiable;
use PHPUnit\Framework\TestCase;

/**
 * The template engine must HTML-escape interpolated notification data (this channel
 * carries password-reset/2FA mail) while leaving the layout's pre-rendered {{{content}}}
 * intact and rejecting non-http(s) action URLs.
 */
final class EmailFormatterEscapingTest extends TestCase
{
    private function formatter(): EmailFormatter
    {
        return new EmailFormatter(new ApplicationContext(sys_get_temp_dir()));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function format(array $data): array
    {
        return $this->formatter()->format($data, new FakeNotifiable('user@example.com'));
    }

    public function test_script_payload_in_data_value_is_escaped_in_html(): void
    {
        $result = $this->format([
            'template_name' => 'welcome',
            'subject' => 'Welcome',
            'name' => '<script>alert(1)</script>',
            'app_name' => 'Acme',
        ]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $result['html_content']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result['html_content']);
    }

    public function test_layout_content_is_not_double_escaped(): void
    {
        // The rendered template HTML is spliced into the layout via {{{content}}}; the
        // wrapper markup (and partial output) must survive as real HTML.
        $result = $this->format([
            'template_name' => 'welcome',
            'subject' => 'Welcome',
            'name' => 'Ada',
            'app_name' => 'Acme',
        ]);

        self::assertStringContainsString('<!DOCTYPE html>', $result['html_content']);
        self::assertStringContainsString('<div class="message">', $result['html_content']);
        // Escaped layout would have turned the wrapper into &lt;div ...&gt;.
        self::assertStringNotContainsString('&lt;div class="message"&gt;', $result['html_content']);
    }

    public function test_javascript_action_url_is_stripped_but_https_passes_through(): void
    {
        $malicious = $this->format([
            'template_name' => 'welcome',
            'subject' => 'Welcome',
            'name' => 'Ada',
            'app_name' => 'Acme',
            'action_url' => 'javascript:alert(1)',
        ]);

        self::assertStringNotContainsString('javascript:alert(1)', $malicious['html_content']);
        // With a blanked URL the conditional button block is dropped entirely.
        self::assertStringNotContainsString('href="javascript', $malicious['html_content']);

        $safe = $this->format([
            'template_name' => 'welcome',
            'subject' => 'Welcome',
            'name' => 'Ada',
            'app_name' => 'Acme',
            'action_url' => 'https://example.com/start?a=1&b=2',
        ]);

        // Present, and attribute-escaped (& -> &amp;) for valid HTML.
        self::assertStringContainsString('https://example.com/start?a=1&amp;b=2', $safe['html_content']);
        self::assertStringNotContainsString('href="https://example.com/start?a=1&b=2"', $safe['html_content']);
    }

    public function test_text_content_has_entities_decoded(): void
    {
        $result = $this->format([
            'template_name' => 'welcome',
            'subject' => 'Welcome',
            'name' => 'Tom & Jerry',
            'app_name' => 'Acme',
        ]);

        // HTML keeps the entity; the text version round-trips it back to a literal '&'.
        self::assertStringContainsString('Tom &amp; Jerry', $result['html_content']);
        self::assertStringContainsString('Tom & Jerry', $result['text_content']);
        self::assertStringNotContainsString('&amp;', $result['text_content']);
    }
}
