<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Confluence\ConfluenceAttachmentListPlaceholderTransformer;
use App\Confluence\ConfluenceImagePlaceholderTransformer;
use App\Model\ImportedPage;

final readonly class ObsidianAttachmentPlaceholderRewriter
{
    private const string UNRESOLVED_ATTACHMENT_WARNING = '> [!warning] Inline attachment unavailable'
        . "\n> The original inline attachment could not be identified.";

    public function __construct(
        private ObsidianPathBuilder $pathBuilder,
        private ObsidianImageLinkBuilderInterface $imageLinkBuilder,
    ) {}

    public function rewrite(ImportedPage $page): ImportedPage
    {
        $replacements = [];
        $referencedAttachmentIds = [];

        if (preg_match_all('/@@OBSIDIAN_ATTACHMENT:att([^@\s]+)@@/', $page->content, $matches) > 0) {
            $referencedAttachmentIds = array_fill_keys($matches[1], true);
        }

        $unreferencedAttachmentLinks = [];
        $unreferencedAttachmentIds = [];

        foreach ($page->attachments as $attachment) {
            $id = str_starts_with($attachment->id, 'att')
                ? substr($attachment->id, 3)
                : $attachment->id;
            $vaultPath = $this->pathBuilder->attachmentVaultPath($page, $attachment);
            $placeholder = sprintf('@@OBSIDIAN_ATTACHMENT:att%s@@', $id);
            $replacements[$placeholder] = $this->isImage($attachment->filename)
                ? $this->imageLinkBuilder->build($vaultPath)
                : $this->buildAttachmentLink($vaultPath, $attachment->filename);

            if (!isset($referencedAttachmentIds[$id]) && !isset($unreferencedAttachmentIds[$id])) {
                $unreferencedAttachmentLinks[] = sprintf(
                    '- %s',
                    $this->buildAttachmentLink($vaultPath, $attachment->filename),
                );
                $unreferencedAttachmentIds[$id] = true;
            }
        }

        $replacements[ConfluenceImagePlaceholderTransformer::UNRESOLVED_ATTACHMENT_PLACEHOLDER] =
            self::UNRESOLVED_ATTACHMENT_WARNING
            . ($unreferencedAttachmentLinks === [] ? '' : ' Check **Attached documents** below.');

        $contentWithoutAttachmentMacros = str_replace(
            [
                "\n\n" . ConfluenceAttachmentListPlaceholderTransformer::PLACEHOLDER . "\n\n",
                ConfluenceAttachmentListPlaceholderTransformer::PLACEHOLDER,
            ],
            ["\n\n", ''],
            $page->content,
        );
        $content = trim(strtr($contentWithoutAttachmentMacros, $replacements));

        if ($unreferencedAttachmentLinks !== []) {
            $content .= ($content === '' ? '' : "\n\n")
                . "## Attached documents\n\n"
                . implode("\n", $unreferencedAttachmentLinks);
        }

        return new ImportedPage(
            id: $page->id,
            title: $page->title,
            content: $content,
            attachments: $page->attachments,
            spaceKey: $page->spaceKey,
            version: $page->version,
            linkedPages: $page->linkedPages,
        );
    }

    private function buildAttachmentLink(string $vaultPath, string $filename): string
    {
        return sprintf('[[%s|%s]]', $vaultPath, $filename);
    }

    private function isImage(string $filename): bool
    {
        return in_array(
            strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            ['avif', 'bmp', 'gif', 'heic', 'heif', 'jpeg', 'jpg', 'png', 'svg', 'webp'],
            true,
        );
    }
}
