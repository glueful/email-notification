<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Tests\Support;

use Glueful\Extensions\EmailNotification\EmailChannel;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * EmailChannel with a capturing transport (via the protected createTransport
 * seam): tests assert the ACTUAL subject/body that reached the transport —
 * a 200 with sent_to alone would stay green while sending the wrong content.
 */
final class CapturingEmailChannel extends EmailChannel
{
    /** @var list<Email> */
    public array $sent = [];

    protected function createTransport(): TransportInterface
    {
        $channel = $this;

        return new class ($channel) implements TransportInterface {
            public function __construct(private readonly CapturingEmailChannel $channel)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($message instanceof Email) {
                    $this->channel->sent[] = $message;
                }

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'capturing://test';
            }
        };
    }
}
