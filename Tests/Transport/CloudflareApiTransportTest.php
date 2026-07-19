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

use JulienRamel\CloudflareMailer\Event\CloudflareBounceEvent;
use JulienRamel\CloudflareMailer\Transport\CloudflareApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CloudflareApiTransportTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('toStringProvider')]
    public function testToString(CloudflareApiTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    public static function toStringProvider(): \Generator
    {
        yield 'default host' => [
            new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN'),
            'cloudflare+api://ACCOUNT_ID@api.cloudflare.com',
        ];

        yield 'custom host' => [
            (new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN'))->setHost('example.com'),
            'cloudflare+api://ACCOUNT_ID@example.com',
        ];

        yield 'custom host and port' => [
            (new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN'))->setHost('example.com')->setPort(8080),
            'cloudflare+api://ACCOUNT_ID@example.com:8080',
        ];
    }

    public function testSendWithNamedSenderUsesStructuredFromObject(): void
    {
        // The Cloudflare REST API expects {"address":"...","name":"..."} for from when a display name is set.
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertIsArray($body['from'], 'from must be an object when a display name is present');
            self::assertSame('fabien@symfony.com', $body['from']['address']);
            self::assertSame('Fabien Potencier', $body['from']['name']);

            return $this->successResponse(['tony@avengers.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from(new Address('fabien@symfony.com', 'Fabien Potencier'))
            ->to('tony@avengers.com')
            ->subject('Hello!')
            ->text('Hello there!');

        $transport->send($email);
    }

    public function testSendWithAnonymousSenderUsesPlainStringFrom(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertIsString($body['from'], 'from must be a plain string when there is no display name');
            self::assertSame('noreply@example.com', $body['from']);

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('noreply@example.com')
            ->to('to@example.com')
            ->subject('Hello!')
            ->text('Body');

        $transport->send($email);
    }

    public function testRecipientsAreSentAsPlainEmailAddresses(): void
    {
        // The Cloudflare REST API only accepts plain email strings for to/cc/bcc.
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertSame(['tony@avengers.com'], $body['to'], 'to must contain plain email addresses only');

            return $this->successResponse(['tony@avengers.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to(new Address('tony@avengers.com', 'Tony Stark'))
            ->subject('Hello!')
            ->text('Body');

        $transport->send($email);
    }

    public function testSendSuccessfulEmail(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            self::assertSame('POST', $method);
            self::assertSame('https://api.cloudflare.com/client/v4/accounts/ACCOUNT_ID/email/sending/send', $url);
            self::assertStringContainsString('Authorization: Bearer API_TOKEN', implode("\n", $options['headers'] ?? $options['request_headers'] ?? []));

            $body = json_decode($options['body'], true);
            self::assertSame('Hello!', $body['subject']);
            self::assertSame('Hello there!', $body['text']);
            self::assertSame('<h1>Hello!</h1>', $body['html']);

            return $this->successResponse(['tony@avengers.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from(new Address('fabien@symfony.com', 'Fabien Potencier'))
            ->to(new Address('tony@avengers.com', 'Tony Stark'))
            ->subject('Hello!')
            ->text('Hello there!')
            ->html('<h1>Hello!</h1>');

        $sentMessage = $transport->send($email);

        self::assertNotNull($sentMessage);
        self::assertStringContainsString('tony@avengers.com', $sentMessage->getDebug());
    }

    public function testSendWithCcAndBcc(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertSame(['cc@example.com'], $body['cc']);
            self::assertSame(['bcc@example.com'], $body['bcc']);

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('Test')
            ->text('Body');

        $transport->send($email);
    }

    public function testReplyToIsTopLevelField(): void
    {
        // reply_to must be a dedicated REST API field, not buried inside the headers object.
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertArrayHasKey('reply_to', $body, 'reply_to must be a top-level API field');
            self::assertSame('reply@example.com', $body['reply_to']);
            self::assertArrayNotHasKey('Reply-To', $body['headers'] ?? [], 'Reply-To must not be duplicated in headers');

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->replyTo('reply@example.com')
            ->subject('Test')
            ->text('Body');

        $transport->send($email);
    }

    public function testSendWithAttachment(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertArrayHasKey('attachments', $body);
            self::assertCount(1, $body['attachments']);
            self::assertSame('invoice.pdf', $body['attachments'][0]['filename']);
            self::assertSame('application/pdf', $body['attachments'][0]['type']);
            self::assertSame('attachment', $body['attachments'][0]['disposition']);
            self::assertNotEmpty($body['attachments'][0]['content']);

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Invoice')
            ->text('See attached')
            ->addPart(new DataPart('%PDF-1.4 content', 'invoice.pdf', 'application/pdf'));

        $transport->send($email);
    }

    public function testSendWithCustomHeaders(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertArrayHasKey('headers', $body);
            self::assertSame('<original@example.com>', $body['headers']['In-Reply-To']);
            self::assertSame('campaign-123', $body['headers']['X-Campaign-ID']);

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Thread reply')
            ->text('Body');

        $email->getHeaders()
            ->addTextHeader('In-Reply-To', '<original@example.com>')
            ->addTextHeader('X-Campaign-ID', 'campaign-123');

        $transport->send($email);
    }

    public function testSendThrowsOnApiError(): void
    {
        $client = new MockHttpClient(fn () => new JsonMockResponse(
            ['success' => false, 'errors' => [['message' => 'Invalid API token']], 'messages' => [], 'result' => null],
            ['http_code' => 401],
        ));

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'INVALID_TOKEN', $client);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Invalid API token');

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Test')
            ->text('Body');

        $transport->send($email);
    }

    public function testSendThrowsWhenTooManyRecipients(): void
    {
        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', new MockHttpClient());

        $email = (new Email())->from('from@example.com')->subject('Test')->text('Body');
        for ($i = 1; $i <= 51; ++$i) {
            $email->addTo("user{$i}@example.com");
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('50');

        $transport->send($email);
    }

    public function testTotalBounceDispatchesEventAndReturnsMessage(): void
    {
        $client = new MockHttpClient(fn () => new JsonMockResponse([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => ['delivered' => [], 'permanent_bounces' => ['ghost@nonexistent.tld'], 'queued' => []],
        ]));

        $bounceEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::any())
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$bounceEvent) {
                if ($event instanceof CloudflareBounceEvent) {
                    $bounceEvent = $event;
                }

                return $event;
            });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client, $dispatcher);

        $email = (new Email())
            ->from('from@example.com')
            ->to('ghost@nonexistent.tld')
            ->subject('Test')
            ->text('Body');

        // A total bounce is a business failure, not a transport failure.
        // No exception is thrown — the application handles it via the event.
        $sentMessage = $transport->send($email);

        self::assertNotNull($sentMessage);
        self::assertNotNull($bounceEvent, 'CloudflareBounceEvent must be dispatched even when all recipients bounce.');
        self::assertSame(['ghost@nonexistent.tld'], $bounceEvent->getBouncedAddresses());
    }

    public function testPartialBounceDispatchesEventAndDoesNotThrow(): void
    {
        $client = new MockHttpClient(fn () => new JsonMockResponse([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                'delivered' => ['valid@example.com'],
                'permanent_bounces' => ['ghost@nonexistent.tld'],
                'queued' => [],
            ],
        ]));

        $bounceEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::any())
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$bounceEvent) {
                if ($event instanceof CloudflareBounceEvent) {
                    $bounceEvent = $event;
                }

                return $event;
            });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client, $dispatcher);

        $email = (new Email())
            ->from('from@example.com')
            ->to('valid@example.com', 'ghost@nonexistent.tld')
            ->subject('Test')
            ->text('Body');

        $sentMessage = $transport->send($email);

        self::assertNotNull($sentMessage);
        self::assertNotNull($bounceEvent, 'CloudflareBounceEvent must have been dispatched for partial bounces.');
        self::assertSame(['ghost@nonexistent.tld'], $bounceEvent->getBouncedAddresses());
        self::assertSame($sentMessage, $bounceEvent->getSentMessage());
    }

    public function testInlineImageCidIsStrippedOfSymfonyPathPrefix(): void
    {
        // Symfony generates CIDs like "@images/logo.png" for embedded images.
        // Cloudflare rejects "@" and "/" in content IDs, so we sanitize to a plain basename
        // and rewrite the matching cid: references in the HTML body.
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertCount(1, $body['attachments']);
            $attachment = $body['attachments'][0];

            self::assertSame('inline', $attachment['disposition']);
            self::assertSame('logo.png', $attachment['filename'], 'Filename must not contain @ or path separators');
            self::assertSame('logo.png', $attachment['content_id'], 'Content-ID must not contain @ or path separators');
            self::assertStringContainsString('cid:logo.png', $body['html'], 'cid: reference in HTML must be rewritten to use the sanitized ID');
            self::assertStringNotContainsString('cid:@images/logo.png', $body['html'], 'Original cid: reference must be removed from HTML');

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $image = new DataPart('fake-png-content', '@images/logo.png', 'image/png', 'base64');
        $image->asInline();

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Inline image test')
            ->html('<img src="cid:@images/logo.png" alt="logo">')
            ->addPart($image);

        $transport->send($email);
    }

    public function testInlineImageWithRealSymfonyContentIdIsSent(): void
    {
        // Mirrors how Symfony\Bridge\Twig\Mime\WrappedTemplatedEmail::image() actually embeds an
        // image: it calls DataPart::getContentId(), which generates and caches a real Content-ID
        // (an IdentificationHeader, not a ParameterizedHeader). Reading it back via
        // getHeaderParameter('Content-ID', 'id') throws a LogicException - this test guards against
        // that regression, which the string "@images/logo.png"-style test above does not catch
        // because it never calls getContentId().
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = json_decode($options['body'], true);

            self::assertCount(1, $body['attachments']);
            $attachment = $body['attachments'][0];

            self::assertSame('inline', $attachment['disposition']);
            self::assertNotEmpty($attachment['content_id']);
            self::assertStringContainsString('cid:'.$attachment['content_id'], $body['html']);

            return $this->successResponse(['to@example.com']);
        });

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $image = new DataPart('fake-png-content', 'logo.png', 'image/png', 'base64');
        $image->asInline();
        $cid = $image->getContentId();

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Inline image test')
            ->html('<img src="cid:'.$cid.'" alt="logo">')
            ->addPart($image);

        $transport->send($email);
    }

    public function testSendQueued(): void
    {
        $client = new MockHttpClient(fn () => new JsonMockResponse([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => ['delivered' => [], 'permanent_bounces' => [], 'queued' => ['to@example.com']],
        ]));

        $transport = new CloudflareApiTransport('ACCOUNT_ID', 'API_TOKEN', $client);

        $email = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Test')
            ->text('Body');

        $sentMessage = $transport->send($email);

        self::assertNotNull($sentMessage);
        self::assertStringContainsString('to@example.com', $sentMessage->getDebug());
    }

    private function successResponse(array $delivered = [], array $bounces = [], array $queued = []): JsonMockResponse
    {
        return new JsonMockResponse([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                'delivered' => $delivered,
                'permanent_bounces' => $bounces,
                'queued' => $queued,
            ],
        ]);
    }
}
