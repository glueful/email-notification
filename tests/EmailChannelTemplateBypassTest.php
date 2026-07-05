<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class EmailChannelTemplateBypassTest extends TestCase
{
    private function channel(): EmailChannel
    {
        return new EmailChannel(new ApplicationContext(sys_get_temp_dir()), [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null', 'dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildEmail(array $data): Email
    {
        $method = new \ReflectionMethod($this->channel(), 'createEmail');
        $method->setAccessible(true);

        /** @var Email $email */
        $email = $method->invoke($this->channel(), $data, 'user@app.test');
        return $email;
    }

    public function test_payload_template_key_does_not_select_a_template(): void
    {
        $email = $this->buildEmail([
            'template' => 'two-factor-pin',
            'subject' => 'Preformatted',
            'html_content' => '<p>Already rendered</p>',
            'text_content' => 'Already rendered',
        ]);

        self::assertSame('Preformatted', $email->getSubject());
        self::assertSame('<p>Already rendered</p>', $email->getHtmlBody());
        self::assertStringNotContainsString('complete your sign-in', (string) $email->getHtmlBody());
    }
}
