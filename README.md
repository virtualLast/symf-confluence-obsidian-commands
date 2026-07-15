# Confluence to Obsidian Importer

A Symfony console application that imports a Confluence page into an Obsidian vault.

The command fetches the requested page from Confluence, renders its content, removes Confluence-specific presentation markup, converts the HTML to Markdown, cleans excessive blank lines, and writes the result to the configured vault.

## Requirements

- PHP 8.4 or later
- Composer
- A Confluence account and API token with access to the page being imported
- An existing Obsidian vault

## Setup

Install the dependencies:

```shell
composer install
```

Create a `.env.local` file so credentials and machine-specific paths remain outside version control:

```dotenv
CONFLUENCE_BASE_URL="https://example.atlassian.net/wiki/"
CONFLUENCE_EMAIL="you@example.com"
CONFLUENCE_API_TOKEN="your-api-token"
OBSIDIAN_VAULT="/absolute/path/to/your/Obsidian/Vault"
```

`CONFLUENCE_BASE_URL` should include the `/wiki/` path for Confluence Cloud.

## Usage

Import a page into the root of the configured vault:

```shell
php bin/console app:confluence:import 123456789
```

The page ID is the numeric identifier found in the Confluence page URL.

To place the note in a directory relative to the vault, provide a destination as the second argument:

```shell
php bin/console app:confluence:import 123456789 "Areas/Engineering"
```

The page title is converted to a lowercase slug and used as the filename. For example, `Authentication Architecture` becomes `authentication-architecture.md`. Missing destination directories are created automatically, and an existing file with the same name is overwritten.

Destinations must be relative paths contained within `OBSIDIAN_VAULT`; absolute paths, parent traversal, and symbolic links are rejected. If a title cannot produce a usable slug, the command uses a filename such as `untitled-26-07-15-14:30:00.md`.

On success, the command prints the imported page title and full output path. Confluence or filesystem errors are displayed and return a failing exit code.

View the complete command help with:

```shell
php bin/console app:confluence:import --help
```

## Tests

Run the complete test suite with:

```shell
php vendor/bin/phpunit
```

Run the complete test, static-analysis, and formatting checks with:

```shell
composer check
```
