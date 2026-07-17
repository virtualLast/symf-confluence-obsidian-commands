<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Confluence\ConfluenceAttachmentListPlaceholderTransformer;
use App\Model\ImportedPage;

final readonly class ObsidianAttachmentPlaceholderRewriter
{
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

        $unreferencedImageLinks = [];

        foreach ($page->attachments as $attachment) {
            $id = str_starts_with($attachment->id, 'att')
                ? substr($attachment->id, 3)
                : $attachment->id;
            $replacements[sprintf('@@OBSIDIAN_ATTACHMENT:att%s@@', $id)] =
                $this->imageLinkBuilder->build(
                    $this->pathBuilder->attachmentVaultPath($page, $attachment),
                );

            if (!isset($referencedAttachmentIds[$id]) && $this->isImage($attachment->filename)) {
                $unreferencedImageLinks[] = $replacements[sprintf(
                    '@@OBSIDIAN_ATTACHMENT:att%s@@',
                    $id,
                )];
            }
        }

        $replacements[ConfluenceAttachmentListPlaceholderTransformer::PLACEHOLDER] = implode(
            "\n",
            $unreferencedImageLinks,
        );

        return new ImportedPage(
            id: $page->id,
            title: $page->title,
            content: strtr($page->content, $replacements),
            attachments: $page->attachments,
        );
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
