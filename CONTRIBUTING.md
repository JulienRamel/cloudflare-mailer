# Contributing

Thank you for considering contributing to `julienramel/cloudflare-mailer`!

## Requirements

- PHP 8.2+
- Composer

## Setup

```bash
git clone https://github.com/julienramel/cloudflare-mailer.git
cd cloudflare-mailer
composer install
```

## Running the test suite

```bash
vendor/bin/phpunit
```

## Static analysis

```bash
vendor/bin/phpstan analyse --configuration phpstan.neon.dist
```

## Coding standards

This project follows the [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html).
Run the fixer before submitting a pull request:

```bash
vendor/bin/php-cs-fixer fix
```

## Pull Requests

- One feature or fix per PR
- Add or update tests for any behaviour change
- Make sure `phpunit`, `phpstan`, and `php-cs-fixer` all pass before opening the PR
- Describe what the PR changes and why in the description

## Reporting bugs

Open an issue on [GitHub](https://github.com/julienramel/cloudflare-mailer/issues) with:
- PHP and Symfony versions
- Steps to reproduce
- Expected vs actual behaviour
