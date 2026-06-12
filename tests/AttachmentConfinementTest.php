<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\Tests\Support\FakeNotifiable;
use PHPUnit\Framework\TestCase;

/**
 * Attachment and embedded-image paths come from notification data (potentially user-influenced)
 * and were previously handed to Symfony with at most a file_exists() check, letting a caller
 * attach arbitrary host files (/etc/passwd, .env, private keys) and exfiltrate them to a chosen
 * recipient. EmailChannel now confines every path to security.attachment_allowed_paths (default:
 * the storage dir): a path that resolves outside the allowed base is a non-retryable
 * 'invalid_attachment' failure, never a silent skip. These tests pin that confinement.
 */
final class AttachmentConfinementTest extends TestCase
{
    private string $allowedBase;
    private string $insideFile;
    private string $outsideFile;
    private string $siblingFile;

    protected function setUp(): void
    {
        // A real allowed base directory with a real file inside it.
        $this->allowedBase = sys_get_temp_dir() . '/mail-attach-' . bin2hex(random_bytes(6));
        mkdir($this->allowedBase, 0700, true);
        $this->insideFile = $this->allowedBase . '/inside.txt';
        file_put_contents($this->insideFile, 'allowed attachment');

        // A real file OUTSIDE the allowed base (a sibling of the base dir, in the temp root).
        $this->outsideFile = sys_get_temp_dir() . '/mail-outside-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($this->outsideFile, 'SECRET: should never be attached');

        // A sibling directory whose name shares the allowed base as a prefix: '<base>-evil'.
        // realpath()-based prefix matching with a trailing separator must NOT treat it as a child.
        mkdir($this->allowedBase . '-evil', 0700, true);
        $this->siblingFile = $this->allowedBase . '-evil/file.txt';
        file_put_contents($this->siblingFile, 'SECRET: sibling-dir trick');
    }

    protected function tearDown(): void
    {
        foreach ([$this->insideFile, $this->outsideFile, $this->siblingFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([$this->allowedBase . '-evil', $this->allowedBase] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    /**
     * Build a channel with an explicit null transport (so a permitted send succeeds) and an
     * explicit attachment allowlist pointing at $this->allowedBase.
     */
    private function channel(): EmailChannel
    {
        $config = [
            'default' => 'null',
            'mailers' => ['null' => ['transport' => 'null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
            'security' => [
                'attachment_allowed_paths' => [$this->allowedBase],
            ],
        ];

        return new EmailChannel(new ApplicationContext(sys_get_temp_dir()), $config);
    }

    public function test_attachment_outside_allowed_base_is_rejected(): void
    {
        $result = $this->channel()->sendNotification(
            new FakeNotifiable('user@app.test'),
            ['subject' => 'Hi', 'attachments' => [$this->outsideFile]]
        );

        self::assertFalse($result->success, 'an attachment outside the allowed base must not send');
        self::assertSame('invalid_attachment', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_traversal_path_is_rejected(): void
    {
        // A traversal form that climbs out of the allowed base back to a real outside file.
        $traversal = $this->allowedBase . '/../' . basename($this->outsideFile);

        $result = $this->channel()->sendNotification(
            new FakeNotifiable('user@app.test'),
            ['subject' => 'Hi', 'attachments' => [['path' => $traversal]]]
        );

        self::assertFalse($result->success, 'a traversal path must not send');
        self::assertSame('invalid_attachment', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_attachment_inside_allowed_base_sends(): void
    {
        $result = $this->channel()->sendNotification(
            new FakeNotifiable('user@app.test'),
            ['subject' => 'Hi', 'attachments' => [$this->insideFile]]
        );

        self::assertTrue($result->success, 'an attachment inside the allowed base should send');
    }

    public function test_embed_image_outside_allowed_base_is_rejected(): void
    {
        // Pre-formatted data (html_content + text_content present) is passed through unchanged by
        // format(), so embedImages survives to createEmail() and is exercised by the validator.
        $result = $this->channel()->sendNotification(
            new FakeNotifiable('user@app.test'),
            [
                'subject' => 'Hi',
                'html_content' => '<p>Hi</p>',
                'text_content' => 'Hi',
                'embedImages' => ['logo' => $this->outsideFile],
            ]
        );

        self::assertFalse($result->success, 'an embedded image outside the base must not send');
        self::assertSame('invalid_attachment', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_sibling_dir_prefix_trick_is_rejected(): void
    {
        // '<base>-evil/file.txt' shares the base path as a string prefix but is NOT inside it.
        $result = $this->channel()->sendNotification(
            new FakeNotifiable('user@app.test'),
            ['subject' => 'Hi', 'attachments' => [$this->siblingFile]]
        );

        self::assertFalse($result->success, 'a sibling-dir prefix trick must not send');
        self::assertSame('invalid_attachment', $result->errorCode);
        self::assertFalse($result->retryable);
    }
}
