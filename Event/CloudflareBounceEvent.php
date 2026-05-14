<?php

/*
 * This file is part of the Cloudflare Mailer package.
 *
 * (c) Julien Ramel <julien@ramel.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JulienRamel\CloudflareMailer\Event;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when Cloudflare reports permanent bounces in the send response.
 *
 * Fired for both partial bounces (some delivered, some bounced) and complete bounces.
 */
final class CloudflareBounceEvent extends Event
{
    /**
     * @param list<string> $bouncedAddresses
     */
    public function __construct(
        private readonly array $bouncedAddresses,
        private readonly SentMessage $sentMessage,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getBouncedAddresses(): array
    {
        return $this->bouncedAddresses;
    }

    public function getSentMessage(): SentMessage
    {
        return $this->sentMessage;
    }
}
