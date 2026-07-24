<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceImagePlaceholderTransformer;
use App\Model\ImportedAttachment;
use PHPUnit\Framework\TestCase;

final class ConfluenceImagePlaceholderTransformerTest extends TestCase
{
    private ConfluenceImagePlaceholderTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new ConfluenceImagePlaceholderTransformer();
    }

    public function testItReplacesAnInternalImageByIdAtItsOriginalPosition(): void
    {
        $html = '<p>Example internal image:</p>'
            . '<p><img data-linked-resource-id="884637698" '
            . 'src="/wiki/download/attachments/1/image.jpg"></p>'
            . '<p>Example external image:</p>'
            . '<p><img src="https://example.com/external.jpg" alt="External"></p>';

        $actual = $this->transformer->transform($html, [
            new ImportedAttachment('att884637698', 'image.jpg', 'image'),
        ]);

        self::assertSame(
            '<p>Example internal image:</p>'
            . '<p>@@OBSIDIAN_ATTACHMENT:att884637698@@</p>'
            . '<p>Example external image:</p>'
            . '<p><img src="https://example.com/external.jpg" alt="External"></p>',
            $actual,
        );
    }

    public function testItMatchesByDownloadUrlBeforeFilenameFallback(): void
    {
        $actual = $this->transformer->transform(
            '<img class="confluence-embedded-image" src="https://site.test/wiki/download/attachments/1/shared.jpg">',
            [
                new ImportedAttachment('10', 'shared.jpg', '', '/wiki/download/attachments/2/shared.jpg'),
                new ImportedAttachment('20', 'different.jpg', '', '/wiki/download/attachments/1/shared.jpg'),
            ],
        );

        self::assertSame('@@OBSIDIAN_ATTACHMENT:att20@@', $actual);
    }

    public function testItUsesFilenameOnlyWithoutAnAttachmentId(): void
    {
        $attachment = new ImportedAttachment('42', 'fallback.jpg', '');

        self::assertSame(
            '@@OBSIDIAN_ATTACHMENT:att42@@',
            $this->transformer->transform(
                '<img class="confluence-embedded-image" '
                . 'data-linked-resource-default-alias="fallback.jpg" src="/other/location">',
                [$attachment],
            ),
        );

        $unmatched = '<img data-linked-resource-id="999" '
            . 'data-linked-resource-default-alias="fallback.jpg" src="/other/location">';
        self::assertSame($unmatched, $this->transformer->transform($unmatched, [$attachment]));
    }

    public function testItDoesNotRemoveAnUnmatchedInternalImage(): void
    {
        $html = '<p><img data-linked-resource-id="999" src="/wiki/download/attachments/1/missing.jpg"></p>';

        self::assertSame(
            $html,
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('42', 'other.jpg', '')],
            ),
        );
    }

    public function testItMatchesUnknownAttachmentExportViewHtmlByAltFilename(): void
    {
        $html = '<span class="confluence-embedded-file-wrapper image-center-wrapper">'
            . '<img class="confluence-embedded-image image-center" alt="icon.jpg" width="760" '
            . 'src="https://example.atlassian.net/wiki/plugins/servlet/confluence/placeholder/'
            . 'unknown-attachment?locale=en_US&amp;version=2"></span>';

        self::assertSame(
            '<span class="confluence-embedded-file-wrapper image-center-wrapper">'
            . '@@OBSIDIAN_ATTACHMENT:att884637698@@</span>',
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('884637698', 'icon.jpg', 'image')],
            ),
        );
    }

    public function testItMarksAnUnidentifiableUnknownAttachmentWithoutGuessing(): void
    {
        $html = '<p class="media-group"><span class="confluence-embedded-file-wrapper">'
            . '<img class="confluence-embedded-file unknown-attachment" '
            . 'src="/wiki/plugins/servlet/confluence/placeholder/unknown-attachment?locale=en_US">'
            . '</span></p>';

        self::assertSame(
            '<p class="media-group"><span class="confluence-embedded-file-wrapper">'
            . ConfluenceImagePlaceholderTransformer::UNRESOLVED_ATTACHMENT_PLACEHOLDER
            . '</span></p>',
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('42', 'unknown.docx', 'document')],
            ),
        );
    }

    public function testItReplacesAFileMacroThumbnailLinkWithAnAttachmentPlaceholder(): void
    {
        $html = '<a href="/wiki/download/attachments/123/report.docx?version=2">'
            . '<img src="/wiki/download/thumbnails/123/report.docx?version=2"></a>';

        self::assertSame(
            '@@OBSIDIAN_ATTACHMENT:att42@@',
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('42', 'report.docx', 'document')],
            ),
        );
    }

    public function testItReplacesATextDownloadLinkWithAnAttachmentPlaceholder(): void
    {
        $html = '<p><a href="/wiki/download/attachments/123/walkthrough.mp4?version=1">'
            . 'walkthrough.mp4</a></p>';

        self::assertSame(
            '<p>@@OBSIDIAN_ATTACHMENT:att42@@</p>',
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('42', 'walkthrough.mp4', 'video')],
            ),
        );
    }

    public function testItSeparatesAnAttachmentPlaceholderFromFollowingText(): void
    {
        $html = '<p><a href="/wiki/download/attachments/123/walkthrough.mp4">'
            . 'walkthrough.mp4</a>If you need access, ask.</p>';

        self::assertSame(
            '<p>@@OBSIDIAN_ATTACHMENT:att42@@ If you need access, ask.</p>',
            $this->transformer->transform(
                $html,
                [new ImportedAttachment('42', 'walkthrough.mp4', 'video')],
            ),
        );
    }
}
