<?php

/*
 * This file is part of the Cloudflare Mailer package.
 *
 * (c) Julien Ramel <julien@ramel.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JulienRamel\CloudflareMailer\Tests\Transport;

use JulienRamel\CloudflareMailer\Transport\CloudflareApiTransport;
use JulienRamel\CloudflareMailer\Transport\CloudflareTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;

class CloudflareTransportFactoryTest extends TestCase
{
    private CloudflareTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new CloudflareTransportFactory();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportsProvider')]
    public function testSupports(Dsn $dsn, bool $expected): void
    {
        self::assertSame($expected, $this->factory->supports($dsn));
    }

    public static function supportsProvider(): \Generator
    {
        yield 'cloudflare+api scheme' => [new Dsn('cloudflare+api', 'default', 'ACCOUNT_ID', 'API_TOKEN'), true];
        yield 'cloudflare scheme' => [new Dsn('cloudflare', 'default', 'ACCOUNT_ID', 'API_TOKEN'), true];
        yield 'other scheme' => [new Dsn('resend+api', 'default', 'ACCOUNT_ID', 'API_TOKEN'), false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('createProvider')]
    public function testCreate(Dsn $dsn, string $expectedString): void
    {
        $transport = $this->factory->create($dsn);

        self::assertInstanceOf(CloudflareApiTransport::class, $transport);
        self::assertSame($expectedString, (string) $transport);
    }

    public static function createProvider(): \Generator
    {
        yield 'cloudflare+api with default host' => [
            new Dsn('cloudflare+api', 'default', 'MY_ACCOUNT', 'MY_TOKEN'),
            'cloudflare+api://MY_ACCOUNT@api.cloudflare.com',
        ];

        yield 'cloudflare with default host' => [
            new Dsn('cloudflare', 'default', 'MY_ACCOUNT', 'MY_TOKEN'),
            'cloudflare+api://MY_ACCOUNT@api.cloudflare.com',
        ];

        yield 'cloudflare+api with custom host' => [
            new Dsn('cloudflare+api', 'custom.example.com', 'MY_ACCOUNT', 'MY_TOKEN'),
            'cloudflare+api://MY_ACCOUNT@custom.example.com',
        ];
    }

    public function testCreateThrowsForUnsupportedScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);

        $this->factory->create(new Dsn('sendgrid+api', 'default', 'key'));
    }
}
