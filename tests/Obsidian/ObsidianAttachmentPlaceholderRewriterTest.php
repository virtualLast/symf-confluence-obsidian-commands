<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Confluence\ConfluenceImagePlaceholderTransformer;
use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianAttachmentPlaceholderRewriter;
use App\Obsidian\ObsidianImageLinkBuilder;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ObsidianAttachmentPlaceholderRewriterTest extends TestCase
{
    public function testItRewritesMultiplePlaceholdersAndAppendsUnreferencedAttachments(): void
    {
        $page = new ImportedPage(
            1,
            'Mike Test Document',
            "Before\n\n@@OBSIDIAN_ATTACHMENT:att884637698@@\n\nMiddle\n\n"
            . "@@OBSIDIAN_ATTACHMENT:att2@@\n\nAfter @@OBSIDIAN_ATTACHMENT-ish:att2@@",
            [
                new ImportedAttachment('884637698', 'wallpaperflare.com_wallpaper.jpg', ''),
                new ImportedAttachment('att2', 'second image.png', ''),
                new ImportedAttachment('3', 'unreferenced.png', ''),
            ],
        );

        $rewriter = new ObsidianAttachmentPlaceholderRewriter(
            new ObsidianPathBuilder(new AsciiSlugger()),
            new ObsidianImageLinkBuilder(),
        );

        self::assertSame(
            "Before\n\n![[Attachments/Confluence/mike-test-document/wallpaperflare.com_wallpaper.jpg]]"
            . "\n\nMiddle\n\n![[Attachments/Confluence/mike-test-document/second image.png]]"
            . "\n\nAfter @@OBSIDIAN_ATTACHMENT-ish:att2@@"
            . "\n\n## Attached documents\n\n"
            . "- [[Attachments/Confluence/mike-test-document/unreferenced.png|unreferenced.png]]",
            $rewriter->rewrite($page)->content,
        );
    }

    public function testItListsAllUnreferencedAttachmentsAtTheEnd(): void
    {
        $page = new ImportedPage(
            1,
            'Mike Test Document',
            "Inline:\n\n@@OBSIDIAN_ATTACHMENT:att1@@\n\nAttachments:\n\n@@OBSIDIAN_ATTACHMENT_LIST@@\n\nAfter",
            [
                new ImportedAttachment('1', 'inline.jpg', ''),
                new ImportedAttachment('2', 'other.png', ''),
                new ImportedAttachment('3', 'notes.pdf', ''),
            ],
        );
        $rewriter = new ObsidianAttachmentPlaceholderRewriter(
            new ObsidianPathBuilder(new AsciiSlugger()),
            new ObsidianImageLinkBuilder(),
        );

        self::assertSame(
            "Inline:\n\n![[Attachments/Confluence/mike-test-document/inline.jpg]]"
            . "\n\nAttachments:\n\nAfter"
            . "\n\n## Attached documents\n\n"
            . "- [[Attachments/Confluence/mike-test-document/other.png|other.png]]\n"
            . "- [[Attachments/Confluence/mike-test-document/notes.pdf|notes.pdf]]",
            $rewriter->rewrite($page)->content,
        );
    }

    public function testItLinksAnInlineDocumentWithoutAddingItToTheFooter(): void
    {
        $page = new ImportedPage(
            1,
            'Mike Test Document',
            '@@OBSIDIAN_ATTACHMENT:att1@@',
            [new ImportedAttachment('1', 'notes.docx', '')],
        );

        self::assertSame(
            '[[Attachments/Confluence/mike-test-document/notes.docx|notes.docx]]',
            $this->createRewriter()->rewrite($page)->content,
        );
    }

    public function testItReplacesEveryUnresolvedAttachmentWithAWarning(): void
    {
        $placeholder = ConfluenceImagePlaceholderTransformer::UNRESOLVED_ATTACHMENT_PLACEHOLDER;
        $page = new ImportedPage(1, 'Example', $placeholder . "\n\n" . $placeholder, []);

        self::assertSame(
            "> [!warning] Inline attachment unavailable\n"
            . "> The original inline attachment could not be identified. Check **Attached documents** below.\n\n"
            . "> [!warning] Inline attachment unavailable\n"
            . '> The original inline attachment could not be identified. Check **Attached documents** below.',
            $this->createRewriter()->rewrite($page)->content,
        );
    }

    private function createRewriter(): ObsidianAttachmentPlaceholderRewriter
    {
        return new ObsidianAttachmentPlaceholderRewriter(
            new ObsidianPathBuilder(new AsciiSlugger()),
            new ObsidianImageLinkBuilder(),
        );
    }
}
