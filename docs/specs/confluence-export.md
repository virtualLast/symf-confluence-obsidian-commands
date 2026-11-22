# Export an imported Obsidian note to Confluence

Approved by Mike in the design interview on 2026-11-22. No Jira key was supplied.

## Intent and acceptance criteria

- Export one existing imported note to its original page on the configured Confluence site.
- Default to a read-only preview showing destination URL, current title/version, complete before/after bodies, and warnings. Require `--publish` for a write.
- Treat the local Markdown body as authoritative, including removal of remote-only content. Preserve title, location, attached files, and the local note.
- Require valid imported page identity, but not an imported version. Fetch current remote state and submit its version plus one; stop concurrent failures without retrying.
- Exclude YAML frontmatter and include the entire body, including its first heading.
- Support basic Markdown and tables. Skip images/file embeds with warnings. Reject detected unsupported local syntax.
- Resolve wikilinks to imported-note page IDs. Use the configured vault with an optional override. Reject missing, ambiguous, and local-only targets; preserve ordinary web links.
- Return nonzero on failure; report published version and URL on success.

## Call path and interface impact

`ExportConfluenceCommand::execute()` calls `ExportNoteReader::read()` and `ExportContentConverter::convert()` before contacting Confluence. Conversion delegates local links to `ExportLinkResolver::resolve()`. The command calls `ConfluenceExportGatewayInterface::getPage()` for the current title, storage body and version, displays the before/after bodies, and calls `publish()` only with `--publish`.

`ConfluenceExportClient` reuses `ConfluenceSdkClientFactoryInterface`. The new external write is Confluence v2 `PUT /pages/{id}` with the existing title, `status: current`, replacement storage body, and next version. No create, attachment, move, or local-write endpoints are used. No database or frontmatter migration is required.

## Verification

Unit tests cover supported formatting, literal code, skipped image forms, wikilinks/aliases/fragments, missing and ambiguous links, unsupported syntax, local-only notes, vault overrides, and symlink escapes. Command tests verify previews never publish, local validation precedes remote access, publishing uses current remote state, failures stop, and local bytes remain unchanged. SDK transport tests assert the actual update URL and JSON payload, error propagation, and absence of retries. Run the full test suite, PHPStan, formatter check, and Symfony command discovery.

## Out of scope

Creating pages, batch publication, automatic synchronization, title/location changes, merging, conflict prompts, attachment upload/deletion, macros, and local metadata updates. Live publication requires a separately selected real destination; automated verification uses test transports.
