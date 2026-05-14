<?php

/*
 * This file is part of the Cloudflare Mailer package.
 *
 * (c) Julien Ramel <julien@ramel.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JulienRamel\CloudflareMailer\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * @author Julien Ramel <julien@ramel.io>
 */
final class CloudflareTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if ('cloudflare+api' !== $dsn->getScheme() && 'cloudflare' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'cloudflare', $this->getSupportedSchemes());
        }

        $accountId = $this->getUser($dsn);
        $apiToken = $this->getPassword($dsn);
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port = $dsn->getPort();

        return (new CloudflareApiTransport($accountId, $apiToken, $this->client, $this->dispatcher, $this->logger))
            ->setHost($host)
            ->setPort($port);
    }

    /**
     * @return list<string>
     */
    protected function getSupportedSchemes(): array
    {
        return ['cloudflare', 'cloudflare+api'];
    }
}
