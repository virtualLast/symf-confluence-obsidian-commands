<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianAttachmentPlaceholderRewriter;
use App\Obsidian\ObsidianImageLinkBuilder;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ObsidianAttachmentPlaceholderRewriterTest extends TestCase
{
    public function testItRewritesMultiplePlaceholdersInPositionWithoutAppendingAttachments(): void
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
            . "\n\nAfter @@OBSIDIAN_ATTACHMENT-ish:att2@@",
            $rewriter->rewrite($page)->content,
        );
        self::assertStringNotContainsString('unreferenced.png', $rewriter->rewrite($page)->content);
    }

    public function testItListsOnlyUnreferencedImagesAtTheAttachmentMacroPosition(): void
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
            . "\n\nAttachments:\n\n![[Attachments/Confluence/mike-test-document/other.png]]\n\nAfter",
            $rewriter->rewrite($page)->content,
        );
    }
}
