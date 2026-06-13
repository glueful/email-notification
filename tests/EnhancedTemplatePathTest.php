<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\EnhancedEmailFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

/**
 * The "enhanced" send path (EnhancedEmailFormatter + $data['template'] set) was doubly broken:
 * (a) buildEmailFromTemplate() ignored its $templateName parameter entirely, so naming a template
 *     selected the default instead, and (b) the enhanced branch in EmailChannel::createEmail()
 *     returned early, dropping cc/bcc/reply-to that the standard branch applied. These tests pin
 *     both fixes: the named template is actually rendered (with the documented precedence vs an
 *     explicit template_name), and cc/bcc/reply-to now survive onto the built Email on the enhanced
 *     path -- through the single shared helper the standard branch also uses.
 */
final class EnhancedTemplatePathTest extends TestCase
{
    private function formatter(): EnhancedEmailFormatter
    {
        return new EnhancedEmailFormatter(new ApplicationContext(sys_get_temp_dir()));
    }

    /**
     * Build a channel whose enhanced template branch is reachable: cc/bcc/reply-to live on the
     * channel, so we exercise createEmail() directly (it is private -- invoked via reflection) with
     * pre-formatted data (html_content + text_content present) carrying a 'template' key.
     *
     * @param array<string, mixed> $config
     */
    private function channel(array $config = []): EmailChannel
    {
        $config = array_merge([
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ], $config);

        return new EmailChannel(new ApplicationContext(sys_get_temp_dir()), $config);
    }

    /**
     * Invoke the private EmailChannel::createEmail() so the built Email can be inspected.
     *
     * @param array<string, mixed> $data
     */
    private function buildEmail(EmailChannel $channel, array $data, string $recipient): Email
    {
        $method = new \ReflectionMethod($channel, 'createEmail');
        $method->setAccessible(true);

        /** @var Email $email */
        $email = $method->invoke($channel, $data, $recipient);
        return $email;
    }

    // --- (a) $templateName is honored -------------------------------------------------------

    public function test_template_name_argument_selects_that_template(): void
    {
        // buildEmailFromTemplate('two-factor-pin', ...) must render the two-factor-pin template,
        // not the default. "complete your sign-in" is body copy unique to that template (the layout
        // partial -- shared by every template -- defines the .otp-container CSS class, so CSS markup
        // is NOT a reliable discriminator; body copy is).
        $email = $this->formatter()->buildEmailFromTemplate('two-factor-pin', ['pin' => '123456']);

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('complete your sign-in', $html);
        self::assertStringContainsString('123456', $html, 'the supplied pin must render in the body');
    }

    public function test_explicit_template_name_in_data_wins_over_argument(): void
    {
        // Documented precedence: an explicit $data['template_name'] WINS over $templateName.
        // Selecting 'default' explicitly while naming 'two-factor-pin' must render the default
        // (the two-factor-pin body copy is absent).
        $email = $this->formatter()->buildEmailFromTemplate(
            'two-factor-pin',
            ['template_name' => 'default', 'pin' => '123456']
        );

        $html = (string) $email->getHtmlBody();
        self::assertStringNotContainsString('complete your sign-in', $html);
    }

    public function test_template_name_argument_used_when_no_explicit_data_template_name(): void
    {
        // The inverse of the precedence test: with no explicit template_name, the argument selects
        // the named template (two-factor-pin) rather than the default.
        $email = $this->formatter()->buildEmailFromTemplate('two-factor-pin', ['pin' => '777777']);

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('complete your sign-in', $html);
        self::assertStringContainsString('777777', $html);
    }

    // --- (b) cc/bcc/reply-to survive on the enhanced path -----------------------------------

    public function test_cc_and_bcc_survive_on_the_enhanced_path(): void
    {
        // Enhanced branch is taken when the formatter is enhanced AND data['template'] is set.
        // Pre-formatted data (html_content + text_content) keeps 'template' through format().
        $email = $this->buildEmail(
            $this->channel(),
            [
                'template' => 'two-factor-pin',
                'html_content' => '<p>Hi</p>',
                'text_content' => 'Hi',
                'subject' => 'Hi',
                'cc' => 'teammate@app.test',
                'bcc' => ['manager@app.test'],
            ],
            'user@app.test'
        );

        $ccs = array_map(static fn ($a) => $a->getAddress(), $email->getCc());
        $bccs = array_map(static fn ($a) => $a->getAddress(), $email->getBcc());

        self::assertContains('teammate@app.test', $ccs, 'cc must survive onto the enhanced-path Email');
        self::assertContains('manager@app.test', $bccs, 'bcc must survive onto the enhanced-path Email');
    }

    public function test_config_reply_to_is_applied_on_the_enhanced_path(): void
    {
        $email = $this->buildEmail(
            $this->channel(['reply_to' => ['address' => 'support@app.test', 'name' => 'Support']]),
            [
                'template' => 'two-factor-pin',
                'html_content' => '<p>Hi</p>',
                'text_content' => 'Hi',
                'subject' => 'Hi',
            ],
            'user@app.test'
        );

        $replyTo = array_map(static fn ($a) => $a->getAddress(), $email->getReplyTo());
        self::assertContains('support@app.test', $replyTo, 'config reply_to must apply on the enhanced path');
    }

    public function test_enhanced_and_standard_paths_apply_cc_identically(): void
    {
        // The two branches share one helper; building the same cc through each must agree. The
        // standard branch is taken when no 'template' key is present.
        $standard = $this->buildEmail(
            $this->channel(),
            [
                'html_content' => '<p>Hi</p>',
                'text_content' => 'Hi',
                'subject' => 'Hi',
                'cc' => 'teammate@app.test',
            ],
            'user@app.test'
        );
        $enhanced = $this->buildEmail(
            $this->channel(),
            [
                'template' => 'two-factor-pin',
                'html_content' => '<p>Hi</p>',
                'text_content' => 'Hi',
                'subject' => 'Hi',
                'cc' => 'teammate@app.test',
            ],
            'user@app.test'
        );

        $ccOf = static fn (Email $e): array => array_map(static fn ($a) => $a->getAddress(), $e->getCc());
        self::assertSame($ccOf($standard), $ccOf($enhanced));
    }
}
