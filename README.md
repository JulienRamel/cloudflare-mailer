# Cloudflare Mailer

[![Tests](https://github.com/julienramel/cloudflare-mailer/actions/workflows/tests.yml/badge.svg)](https://github.com/julienramel/cloudflare-mailer/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Symfony Mailer bridge for [Cloudflare Email Service](https://developers.cloudflare.com/email-service/).

> **Note:** Cloudflare Email Sending is currently in **public beta**. The API may evolve before general availability. Pin your dependency to a specific version of this package.

## Requirements

- PHP 8.2+
- Symfony Mailer 6.4+
- A Cloudflare account with [Email Sending configured](https://developers.cloudflare.com/email-service/get-started/send-emails/)

## Installation

```bash
composer require julienramel/cloudflare-mailer
```

## Configuration

### DSN

```
MAILER_DSN=cloudflare+api://ACCOUNT_ID:API_TOKEN@default
```

| Part | Description |
|------|-------------|
| `ACCOUNT_ID` | Your [Cloudflare Account ID](https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/) |
| `API_TOKEN` | A Cloudflare API token with **Email Sending** permission |

### Symfony Mailer (`config/packages/mailer.yaml`)

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

### Register the transport factory (`config/services.yaml`)

```yaml
services:
    JulienRamel\CloudflareMailer\Transport\CloudflareTransportFactory:
        tags:
            - { name: mailer.transport_factory }
```

## Usage

```php
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

class MyService
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function sendWelcome(string $to): void
    {
        $email = (new Email())
            ->from(new Address('noreply@yourdomain.com', 'My App'))
            ->to($to)
            ->subject('Welcome!')
            ->text('Thanks for signing up.')
            ->html('<h1>Thanks for signing up.</h1>');

        $this->mailer->send($email);
    }
}
```

## Handling bounces

Unlike most email providers (which report bounces asynchronously via webhooks),
Cloudflare includes permanent bounce information **directly in the API response**.
This bridge surfaces it via a Symfony event so your application can react immediately.

### How it works

```
send() called
    │
    ├── HTTP error / API failure  →  HttpTransportException thrown
    │
    └── HTTP 200 + success: true
            │
            ├── no permanent bounces  →  SentMessage returned, nothing else
            │
            └── permanent bounces present  →  CloudflareBounceEvent dispatched
                                               SentMessage returned (always)
```

A bounce — even a total one — is a **business failure, not a transport failure**.
The API call itself succeeded. No exception is thrown; your listener decides what to do.

### Registering the listener

```php
use JulienRamel\CloudflareMailer\Event\CloudflareBounceEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CloudflareBounceListener
{
    public function __construct(
        private readonly ContactRepository $contacts,
    ) {}

    public function __invoke(CloudflareBounceEvent $event): void
    {
        foreach ($event->getBouncedAddresses() as $address) {
            // The address does not exist or is permanently unreachable.
            // Common reactions: mark as invalid, remove from mailing list,
            // alert your ops team, increment a counter, etc.
            $this->contacts->markAsUndeliverable($address);
        }
    }
}
```

### Treating a total bounce as a fatal error

If your use case requires an exception when nobody received the email, throw it
yourself inside the listener:

```php
use JulienRamel\CloudflareMailer\Event\CloudflareBounceEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Exception\TransportException;

#[AsEventListener]
final class StrictBounceListener
{
    public function __invoke(CloudflareBounceEvent $event): void
    {
        $result = $event->getSentMessage()->getDebug();
        // Check if anything was delivered by inspecting the debug output,
        // or keep track of delivered/bounced counts in your own logic.

        // Throw to propagate the failure up the call stack:
        throw new TransportException(\sprintf(
            'Email permanently bounced for: %s',
            implode(', ', $event->getBouncedAddresses()),
        ));
    }
}
```

### Inspecting results without a listener

`SentMessage::getDebug()` always contains a human-readable summary visible
in the Symfony web profiler:

```
Cloudflare Email result:
  Delivered: alice@example.com
  Queued:    none
  Permanent bounces: ghost@nonexistent.tld
```

## Supported features

| Feature | Supported |
|---------|-----------|
| Plain text body | ✅ |
| HTML body | ✅ |
| CC / BCC | ✅ |
| Reply-To | ✅ |
| Attachments | ✅ |
| Inline images (`cid:`) | ✅ |
| Custom headers | ✅ |
| Bounce detection | ✅ (synchronous, via event) |
| SMTP | ❌ (API only) |

## Known limitations

**Recipient display names are not supported.** The Cloudflare REST API only accepts plain email addresses for `to`, `cc`, and `bcc` fields. If you set `new Address('john@example.com', 'John Doe')` as a recipient, only `john@example.com` will be sent — the display name `John Doe` will not appear in the delivered email's `To` header.

The sender (`from`) supports display names via the `{"address": "...", "name": "..."}` format.

**Maximum 50 recipients** combined across `to`, `cc`, and `bcc`. An `InvalidArgumentException` is thrown before the API call if this limit is exceeded.

**Single `Reply-To` address.** If multiple reply-to addresses are set, only the first one is sent.

## Domain setup

Before sending, your domain must be onboarded in Cloudflare Email Sending.
Cloudflare will add the necessary DNS records (MX, SPF, DKIM, DMARC) automatically.
See the [official documentation](https://developers.cloudflare.com/email-service/get-started/send-emails/#set-up-your-domain).

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
