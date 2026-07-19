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

use JulienRamel\CloudflareMailer\Event\CloudflareBounceEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends emails via the Cloudflare Email Service REST API.
 *
 * DSN format: cloudflare+api://ACCOUNT_ID:API_TOKEN@default
 *
 * @see https://developers.cloudflare.com/email-service/api/send-emails/rest-api/
 *
 * @author Julien Ramel <julien@ramel.io>
 */
final class CloudflareApiTransport extends AbstractApiTransport
{
    private const API_HOST = 'api.cloudflare.com';

    /**
     * The Cloudflare API enforces a combined limit of 50 recipients across to, cc, and bcc.
     */
    private const MAX_RECIPIENTS = 50;

    /**
     * AbstractTransport::$dispatcher is private, so we store our own reference
     * to be able to dispatch bounce events from doSendApi().
     */
    private readonly ?EventDispatcherInterface $eventDispatcher;

    public function __construct(
        #[\SensitiveParameter] private readonly string $accountId,
        #[\SensitiveParameter] private readonly string $apiToken,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
        $this->eventDispatcher = $dispatcher;
    }

    public function __toString(): string
    {
        return \sprintf('cloudflare+api://%s@%s', $this->accountId, $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        // AbstractHttpTransport always initializes $client via HttpClient::create() when null is passed.
        $client = $this->client ?? throw new \LogicException('HTTP client is not initialized.');

        $payload = $this->buildPayload($email, $envelope);

        $response = $client->request('POST', \sprintf(
            'https://%s/client/v4/accounts/%s/email/sending/send',
            $this->getEndpoint(),
            rawurlencode($this->accountId),
        ), [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiToken,
            ],
        ]);

        $statusCode = 0;

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the Cloudflare Email API.', $response, 0, $e);
        }

        if (200 !== $statusCode || !($result['success'] ?? false)) {
            $errors = implode(', ', array_map(
                static fn (array $e) => $e['message'] ?? 'unknown error',
                $result['errors'] ?? [],
            ));

            throw new HttpTransportException(\sprintf('Unable to send an email: %s (code %d).', $errors ?: 'unknown error', $statusCode), $response);
        }

        $sendResult = $result['result'] ?? [];
        $permanentBounces = $sendResult['permanent_bounces'] ?? [];
        $delivered = $sendResult['delivered'] ?? [];
        $queued = $sendResult['queued'] ?? [];

        $sentMessage->appendDebug(\sprintf(
            "Cloudflare Email result:\n  Delivered: %s\n  Queued: %s\n  Permanent bounces: %s",
            implode(', ', $delivered) ?: 'none',
            implode(', ', $queued) ?: 'none',
            implode(', ', $permanentBounces) ?: 'none',
        ));

        if ([] !== $permanentBounces) {
            $this->eventDispatcher?->dispatch(new CloudflareBounceEvent($permanentBounces, $sentMessage));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Email $email, Envelope $envelope): array
    {
        $totalRecipients = \count($envelope->getRecipients());
        if ($totalRecipients > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(\sprintf('Cloudflare Email API does not support more than %d recipients (to + cc + bcc combined); got %d.', self::MAX_RECIPIENTS, $totalRecipients));
        }

        $payload = [
            // Prefer the email's From header (has display name) over the envelope sender (bounce routing only).
            'from' => $this->serializeFrom($email->getFrom()[0] ?? $envelope->getSender()),
            // The REST API accepts plain email address strings only for to/cc/bcc (no display names).
            'to' => array_values(array_map(
                static fn (Address $a) => $a->getEncodedAddress(),
                $this->getRecipients($email, $envelope),
            )),
            'subject' => $email->getSubject(),
        ];

        if ($email->getTextBody()) {
            $payload['text'] = $email->getTextBody();
        }

        if ($email->getHtmlBody()) {
            $payload['html'] = $email->getHtmlBody();
        }

        if ($addresses = $email->getCc()) {
            $payload['cc'] = array_values(array_map(
                static fn (Address $a) => $a->getEncodedAddress(),
                $addresses,
            ));
        }

        if ($addresses = $email->getBcc()) {
            $payload['bcc'] = array_values(array_map(
                static fn (Address $a) => $a->getEncodedAddress(),
                $addresses,
            ));
        }

        // reply_to is a dedicated top-level REST API field, not a custom header.
        if ($addresses = $email->getReplyTo()) {
            $payload['reply_to'] = current($addresses)->getEncodedAddress();
        }

        [$attachments, $cidMap] = $this->buildAttachments($email);

        if ([] !== $attachments) {
            $payload['attachments'] = $attachments;
        }

        // Rewrite cid: references in the HTML to use sanitized content IDs.
        if ([] !== $cidMap && isset($payload['html'])) {
            $html = (string) $payload['html'];
            foreach ($cidMap as $original => $sanitized) {
                $html = str_replace('cid:'.$original, 'cid:'.$sanitized, $html);
            }
            $payload['html'] = $html;
        }

        if ($headers = $this->buildCustomHeaders($email)) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /**
     * Symfony uses internal resource paths (e.g. "@images/logo.png") as Content-ID and filename
     * for embedded images. Cloudflare rejects these because they contain "@" and "/" characters.
     * This method sanitizes both fields to plain basenames and returns a CID map so the caller
     * can rewrite the matching cid: references in the HTML body.
     *
     * @return array{list<array<string, mixed>>, array<string, string>}
     */
    private function buildAttachments(Email $email): array
    {
        $attachments = [];
        $cidMap = [];

        foreach ($email->getAttachments() as $attachment) {
            $preparedHeaders = $attachment->getPreparedHeaders();
            $disposition = $preparedHeaders->getHeaderBody('Content-Disposition');
            $contentTypeHeader = $preparedHeaders->get('Content-Type');

            $rawFilename = $preparedHeaders->getHeaderParameter('Content-Disposition', 'filename');
            $filename = null !== $rawFilename ? basename(ltrim($rawFilename, '@')) : null;

            $item = [
                'content' => str_replace("\r\n", '', $attachment->bodyToString()),
                'filename' => $filename,
                'type' => $contentTypeHeader ? $contentTypeHeader->getBody() : 'application/octet-stream',
                'disposition' => 'inline' === $disposition ? 'inline' : 'attachment',
            ];

            if ('inline' === $disposition) {
                // Content-ID is an IdentificationHeader, not a ParameterizedHeader (unlike Content-Disposition
                // and Content-Type) - reading it via getHeaderParameter() throws a LogicException. DataPart
                // exposes the CID directly, so read it from there instead of parsing the header.
                $rawContentId = $attachment->hasContentId()
                    ? $attachment->getContentId()
                    : $preparedHeaders->getHeaderParameter('Content-Type', 'name');
                if ($rawContentId) {
                    $originalCid = trim($rawContentId, '<>');
                    $sanitizedCid = basename(ltrim($originalCid, '@'));
                    if ($sanitizedCid !== $originalCid) {
                        $cidMap[$originalCid] = $sanitizedCid;
                    }
                    $item['content_id'] = $sanitizedCid;
                }
            }

            $attachments[] = $item;
        }

        return [$attachments, $cidMap];
    }

    /**
     * @return array<string, string>
     */
    private function buildCustomHeaders(Email $email): array
    {
        $headers = [];
        $headersToBypass = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'subject',
            'content-type', 'sender', 'mime-version', 'message-id', 'date',
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    /**
     * Serializes the sender address to the format expected by the Cloudflare REST API:
     * - plain email string when there is no display name
     * - {"address": "...", "name": "..."} object when a display name is present.
     *
     * @return string|array{address: string, name: string}
     */
    private function serializeFrom(Address $address): string|array
    {
        if ($address->getName()) {
            return ['address' => $address->getEncodedAddress(), 'name' => $address->getName()];
        }

        return $address->getEncodedAddress();
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::API_HOST).($this->port ? ':'.$this->port : '');
    }
}
