<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EmailNotification\EmailChannel;
use Glueful\Extensions\EmailNotification\DummyNotifiable;
use Glueful\Extensions\EmailNotification\Tests\Support\FakeNotifiable;
use PHPUnit\Framework\TestCase;

/**
 * EmailChannel enforces the recipient-domain policy (security.allowed_domains /
 * blocked_domains) before sending, and maps outcomes to a structured NotificationResult.
 */
final class EmailChannelDomainPolicyTest extends TestCase
{
    /**
     * @param array<string, mixed> $security
     */
    private function channel(array $security): EmailChannel
    {
        $config = [
            'default' => 'null',
            'mailers' => ['null' => ['dsn' => 'null://null']],
            'from' => ['address' => 'noreply@app.test', 'name' => 'App'],
            'security' => $security,
        ];

        return new EmailChannel(new ApplicationContext(sys_get_temp_dir()), $config);
    }

    public function test_no_recipient_is_a_non_retryable_failure(): void
    {
        $result = $this->channel([])->sendNotification(new DummyNotifiable(), []);

        self::assertFalse($result->success);
        self::assertSame('no_recipient', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_blocked_domain_is_rejected_before_send(): void
    {
        $result = $this->channel(['blocked_domains' => 'spam.test, evil.test'])
            ->sendNotification(new FakeNotifiable('user@spam.test'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_allowlist_rejects_a_non_listed_domain(): void
    {
        $result = $this->channel(['allowed_domains' => 'yourcompany.com'])
            ->sendNotification(new FakeNotifiable('user@other.com'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
    }

    public function test_allowlisted_domain_passes_policy_and_sends_via_null_transport(): void
    {
        $result = $this->channel(['allowed_domains' => 'yourcompany.com'])
            ->sendNotification(new FakeNotifiable('user@yourcompany.com'), ['subject' => 'Hi']);

        self::assertTrue($result->success, 'an allowlisted recipient should pass and send');
    }

    public function test_no_policy_allows_any_domain(): void
    {
        $result = $this->channel([])
            ->sendNotification(new FakeNotifiable('anyone@anywhere.test'), ['subject' => 'Hi']);

        self::assertTrue($result->success);
    }

    public function test_cc_to_a_blocked_domain_is_rejected(): void
    {
        $result = $this->channel(['blocked_domains' => 'spam.test, evil.test'])
            ->sendNotification(
                new FakeNotifiable('user@allowed.test'),
                ['subject' => 'Hi', 'cc' => 'leak@evil.test']
            );

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_bcc_to_a_non_allowlisted_domain_is_rejected(): void
    {
        $result = $this->channel(['allowed_domains' => 'yourcompany.com'])
            ->sendNotification(
                new FakeNotifiable('user@yourcompany.com'),
                ['subject' => 'Hi', 'bcc' => ['leak@other.com']]
            );

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function test_blocklist_rejects_subdomains_of_a_blocked_domain(): void
    {
        $result = $this->channel(['blocked_domains' => 'evil.com'])
            ->sendNotification(new FakeNotifiable('user@sub.evil.com'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
    }

    public function test_allowlist_is_exact_match_and_rejects_subdomains(): void
    {
        // Pins the asymmetry: blocklist matches subdomains, allowlist does not.
        $result = $this->channel(['allowed_domains' => 'company.com'])
            ->sendNotification(new FakeNotifiable('user@sub.company.com'), ['subject' => 'Hi']);

        self::assertFalse($result->success);
        self::assertSame('blocked_domain', $result->errorCode);
    }

    public function test_cc_and_bcc_to_allowed_domains_still_send(): void
    {
        $result = $this->channel(['allowed_domains' => 'yourcompany.com'])
            ->sendNotification(
                new FakeNotifiable('user@yourcompany.com'),
                [
                    'subject' => 'Hi',
                    'cc' => 'teammate@yourcompany.com',
                    'bcc' => ['manager@yourcompany.com'],
                ]
            );

        self::assertTrue($result->success, 'allowlisted cc/bcc recipients should pass and send');
    }
}
