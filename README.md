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
CONFLUENCE_BASE_URL="https://example.atlassian.net"
CONFLUENCE_EMAIL="you@example.com"
CONFLUENCE_API_TOKEN="your-api-token"
OBSIDIAN_VAULT="/absolute/path/to/your/Obsidian/Vault"
```

`CONFLUENCE_BASE_URL` must be the HTTPS Confluence site root and must not include `/wiki/`.

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

### Metadata

Every imported note starts with YAML frontmatter containing its Confluence page ID, title, space key, and current version. Links to other Confluence pages are also recorded with their page ID, title, and original URL under `relationships.linkedPages`. External links are not included, and links in the Markdown body are left unchanged.

This metadata makes each note self-identifying for future synchronisation, indexing, and Confluence-to-Obsidian link resolution without requiring a separate database.

### Resolving Confluence links

After importing pages, convert links between imported Confluence notes into native Obsidian wikilinks:

```shell
php bin/console app:obsidian:resolve-confluence-links
```

By default, the resolver recursively processes Markdown notes beneath `Inbox/Confluence` in the configured vault. Supply another vault-relative directory when needed:

```shell
php bin/console app:obsidian:resolve-confluence-links Inbox/Testing
```

The resolver indexes only notes marked with `source: confluence`, matches them by `confluence.pageId`, and uses the recorded relationship URLs to identify links in each note body. Links to pages that have not been imported remain unchanged. Frontmatter is preserved, only changed files are written, and repeated runs are safe.

Duplicate Confluence page IDs make the index ambiguous, so the command reports them and stops before changing any files. Use `-v` to list invalid notes, duplicate paths, unresolved page IDs, and updated files.

View the complete command help with:

```shell
php bin/console app:confluence:import --help
```

### Attachments

Attachments are opt-in. Download them alongside the page with:

```shell
php bin/console app:confluence:import 123456789 --with-attachments
```

Downloaded files are written to `Attachments/Confluence/<page-slug>/` in the vault. Images that Confluence identifies by attachment ID, download URL, or filename are embedded at their original positions. Identified documents such as DOCX and PDF files are linked at their original positions.

Confluence sometimes renders a file as an anonymous `unknown-attachment` without an ID or filename. The importer does not guess which downloaded file belongs there. It inserts an `Inline attachment unavailable` warning at that position instead.

Every downloaded attachment that was not reliably linked inline is listed once under `## Attached documents` at the end of the note. The list uses ordinary Obsidian links so images and documents are not unnecessarily embedded a second time.

### Recursive imports

Follow the Confluence child-page hierarchy from a root page with:

```shell
php bin/console app:confluence:import 123456789 Inbox/Confluence --recursive
```

Follow Confluence pages linked from each imported page with:

```shell
php bin/console app:confluence:import 123456789 Inbox/Confluence --follow-links
```

Both strategies can be combined and constrained to a shared maximum depth:

```shell
php bin/console app:confluence:import 123456789 Inbox/Confluence \
    --recursive \
    --follow-links \
    --depth=2
```

The root page is depth zero. Pages at the maximum depth are imported but are not expanded. Without `--depth`, traversal continues until no newly discovered page IDs remain. Breadth-first traversal imports each page at most once per command, even when cycles exist or a page is discovered through both hierarchy and links.

The destination and `--with-attachments` setting apply to every imported page. A failed descendant is reported while independent queued branches continue; root-page failures stop traversal immediately. Use `-v` for per-page traversal and failure details.

### Folder imports

Import every page contained in a Confluence folder hierarchy with:

```shell
php bin/console app:confluence:import-folder 404717571 Inbox/Confluence
```

The selected folder and each nested folder become directories beneath the requested destination. Folder names retain readable capitalization and spaces while unsafe filename characters are normalised. Pages are imported through the same single-page pipeline as `app:confluence:import`, and each Confluence page ID is imported at most once even if it appears in multiple folders.

Confluence pages can also contain a mixed hierarchy of child pages and folders. A page with direct children remains a Markdown note in its parent directory and receives a same-named directory for those descendants. For example, `Projects` is written as `projects.md`, while its children are imported beneath `Projects/`. This continues recursively through both page and folder containers.

Attachments remain opt-in:

```shell
php bin/console app:confluence:import-folder 404717571 Inbox/Confluence \
    --with-attachments
```

Independent page or nested-folder failures are reported after the remaining hierarchy has been processed. Use `-v` to list traversed folders, imported pages, duplicate discoveries, and failures.

### Code blocks

Confluence syntax-highlighter brushes are converted into Markdown fence languages. Common aliases such as `js`, `ts`, `sh`, and `yml` become `javascript`, `typescript`, `bash`, and `yaml` respectively.

This project treats Confluence's `java` brush as `markdown`. This reflects the source documentation, where Markdown examples have been labelled as Java by Confluence, and causes them to render as `markdown` fenced code blocks in Obsidian.

## Tests

Run the complete test suite with:

```shell
php vendor/bin/phpunit
```

Run the complete test, static-analysis, and formatting checks with:

```shell
composer check
```
