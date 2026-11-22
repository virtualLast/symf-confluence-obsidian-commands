# Expand and image round trips

Approved by Mike on 2026-11-22, extending the original export specification. No Jira key supplied.

## Behaviour

Preserve rendered Confluence Expand sections as collapsible Obsidian callouts (`> [!note]- Title`) on import. Export collapsible callouts as native Expand macros, preserving nested rich content. Existing flattened notes require re-import or explicit callout markers; ordinary headings remain ordinary headings.

Export local Markdown images and image wikilink embeds as page attachments. Use deterministic names derived from vault-relative paths so repeated exports update existing exporter-managed attachments and different paths do not collide. Preserve external HTTP(S) images as external image references. Keep non-image embeds skipped with warnings, and fail missing or unsafe local image paths. Preserve image size hints where supplied. Never delete unused attachments or alter local files.

## Call path and external interface changes

Import: `ConfluenceHtmlCleaner::clean()` retains each Expand title as metadata before removing UI controls; a custom HTML-to-Markdown div converter produces callouts after child content conversion.

Export: `ExportContentConverter::convert()` transforms parsed collapsible blockquotes into Expand nodes and resolves images into storage references plus a deduplicated attachment plan. A read-only preview lists planned uploads. With `--publish`, `ConfluenceAttachmentPublisherInterface` uploads images using v1 `PUT /wiki/rest/api/content/{id}/child/attachment` (multipart `file`, create-or-update by filename) before the existing v2 page-body update. No schema changes or metadata writes.

The two operations are not atomic. Stop on the first failure and report that completed attachment updates can remain even if the page body was not published. Do not retry writes or delete completed attachments as rollback. Existing page-version conflict detection remains in place.

## Verification

Test Expand titles, nested content, tables/code/images, and ordinary blockquotes through import and export; test local/external images, repeated references, path collisions, changed content with stable filenames, missing files and escapes. Inspect multipart HTTP requests, failure propagation, and command ordering. Preview must never upload. Run all repository checks and an independent review. Use synthetic files/transports, not live publication.

API reference: [Confluence Cloud attachment operations](https://developer.atlassian.com/cloud/confluence/rest/v1/api-group-content---attachments/).
