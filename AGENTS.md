# Repository guidance

This is a PHP/Symfony console application. Read [ARCHITECTURE.md](ARCHITECTURE.md) when changing data flow or integration boundaries, and the relevant [specification](docs/specs/) before changing an approved feature.

## Checks

- Install: `composer install`.
- Verify: `composer check` (PHPUnit, PHPStan, and formatting).
- Format: `vendor/bin/php-cs-fixer fix --sequential`.
- Discover commands: `php bin/console list --no-debug`.

## Integration pitfalls

- Preserve the separation between import's rendered HTML and export's storage markup.
- Test Confluence writes with `tests/Support/QueueClient`; inspect the outgoing JSON, because generated SDK union fields may not normalize model objects.
- Keep credentials in environment configuration. Use synthetic notes and transports for tests; live publication changes remote content.
- The installed Symfony session handler can fail debug boot under PHP 8.6 due to a deprecation. `--no-debug` permits command discovery without changing dependencies.

No repository-specific Standards block or central glossary URL was established before this change. Follow existing PHP conventions and the approved scope; record any broader standards adoption with the owner.
