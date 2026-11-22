# Confluence and Obsidian Commands

A Symfony console application that imports Confluence pages into an Obsidian vault and explicitly exports edited notes back to their original pages.

Maintained by Lightfoot. See [architecture](ARCHITECTURE.md) for component boundaries and data flow.

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

### Exporting a note back to Confluence

Preview the original page and its proposed replacement body:

```shell
php bin/console app:confluence:export "Inbox/Confluence/my-page.md"
```

Publish the replacement explicitly:

```shell
php bin/console app:confluence:export "Inbox/Confluence/my-page.md" --publish
```

The note path may be absolute or relative to `OBSIDIAN_VAULT`, and must resolve inside that vault. Use `--vault=/absolute/path/to/vault` to override it for both the selected note and link resolution. The note must have `source: confluence` and a valid `confluence.pageId`. The configured Confluence credentials need permission to update the destination.

The preview shows the full destination URL, current page title and version, current storage markup, proposed storage markup, and warnings. It performs a remote read but no writes. Publishing displays the same preview and then replaces the body. The configured site must be the one the note came from: imported metadata does not record a site address.

**The local body is authoritative.** Direct Confluence edits, macros, layouts, and other content absent from the exported body are overwritten. The entire Markdown body is included, including its first heading; YAML frontmatter is excluded. The title, parent location, and local note are left unchanged. Referenced local images are uploaded or updated; unused attachments are left untouched. The imported version is ignored. Publishing fetches the latest remote version and submits one update; concurrent-update failures stop without retrying. Transport failures may have an uncertain outcome: inspect the page before retrying.

Supported content includes headings, paragraphs, emphasis, lists, code, tables, and links. Local Markdown images and Obsidian image embeds (`![[...]]`) are uploaded as page attachments. HTTP(S) image URLs remain external references. Non-image file/note embeds are omitted with warnings. Attachment deletion is not implemented. Raw HTML/Confluence markup and detected unsupported Obsidian syntax (including non-collapsible callouts, comments, footnotes, highlights, and block links) fail validation. Code examples remain literal.

Wikilinks such as `[[note#Heading|Label]]` resolve to the target imported note's original Confluence page. Bare names must be unique across the vault; paths such as `[[Folder/note]]` are vault-relative, and `[[./note]]` is relative to the source note. Ordinary relative Markdown links resolve relative to the source file. Missing, ambiguous, or local-only targets block publication. HTTP, HTTPS, and mail links are preserved. Local links never cause other notes to be published.

#### Expand sections

Imported Confluence Expand sections become collapsible callouts:

```markdown
> [!note]- Section title
> Content inside the section.
>
> - List item
```

Export converts collapsible callouts (`[!type]-` or `[!type]+`) into native Confluence Expand macros. Titles and nested content are preserved; Confluence controls the initial collapsed display. Ordinary headings and blockquotes stay unchanged. Expand sections already flattened by older imports need re-importing or explicit callout markers. Re-import overwrites local notes, so preserve local edits first.

#### Images and attachment updates

The preview lists each planned upload with its vault-relative path, destination filename, and byte count. Uploads happen only with `--publish`, before the body update. Local image paths must resolve inside the vault; missing, unreadable, ambiguous, or invalid images stop the export. Supported image formats are PNG, JPEG, GIF, WebP, SVG, BMP, and AVIF. `![[image.png|300]]` and `![[image.png|300x200]]` preserve size hints.

Bare wikilink image names must be unique within the vault. Paths in image wikilinks are vault-relative; `./` and `../` are relative to the note. Markdown image paths are note-relative, with a leading `/` selecting the vault root.

Attachments receive names such as `obsidian-<path-hash>-image.png`. The hash uses the vault-relative path, so changed image bytes update the same attachment and different paths get different names. Moving or renaming an image creates a new managed attachment. The first export creates a managed copy rather than overwriting an unrelated original attachment with the same basename. Repeated references in a note upload once per invocation; repeated publications may add attachment versions even when image bytes are unchanged.

Attachment and page updates are not atomic. If an upload or the later page update fails, already completed attachment updates can remain. The command stops, reports this partial outcome, and does not retry or delete attachments. Existing remote edits are still overwritten on a subsequent explicit publish.

See the [Expand and image extension specification](docs/specs/confluence-expand-images.md).

Success returns exit code 0; validation, read, or publish failures return 1. Publishing reports the resulting version and page URL. See the [approved export specification](docs/specs/confluence-export.md).

On PHP 8.6, the installed Symfony session handler currently raises a deprecation during debug-mode startup. Use `php bin/console --no-debug app:confluence:export ...` if affected. This also applies to other commands; it is not an export failure.

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
