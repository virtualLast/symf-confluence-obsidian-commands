<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluenceContentConverter;
use App\Confluence\ConfluenceImporter;
use App\Confluence\ConfluenceLinkedPageExtractor;
use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentAttachment;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;
use PHPUnit\Framework\TestCase;

class ConfluenceImporterTest extends TestCase
{
    /**
     * What: Importing one page fetches that page, not its children, and maps its fields.
     * How: Client and page doubles define the response, calls, and expected ImportedPage values.
     * Why: A single-page import must remain scoped and faithfully transfer the source data.
     */
    public function testImportPageFetchesPageFromConfluence(): void
    {
        // arrange
        $converter = $this->createConfluenceContentConverter();
        $pageId = 664600597;
        $title = 'Test page';
        $content = 'Test content';

        $pageStub = $this->createStub(AbstractContent::class);
        $pageStub
            ->method('getId')
            ->willReturn($pageId)
        ;
        $pageStub
            ->method('getTitle')
            ->willReturn($title)
        ;
        $pageStub
            ->method('getContent')
            ->willReturn($content)
        ;
        $pageStub->method('getSpace')->willReturn('SD');
        $pageStub->method('getVersion')->willReturn(17);

        $confluenceClientMock = $this->createMock(ConfluenceClient::class);
        $confluenceClientMock
            ->expects($this->once())
            ->method('getPage')
            ->with($pageId)
            ->willReturn($pageStub)
        ;

        $confluenceClientMock
            ->expects($this->never())
            ->method('getPageChildren')
        ;

        // act
        $importer = new ConfluenceImporter(
            $confluenceClientMock,
            $converter,
            new ConfluenceLinkedPageExtractor('https://example.atlassian.net/wiki/'),
        );
        $importedPage = $importer->importPage($pageId);

        // assert
        $this->assertInstanceOf(ImportedPage::class, $importedPage);
        $this->assertSame($pageId, $importedPage->id);
        $this->assertSame($title, $importedPage->title);
        $this->assertSame($content, $importedPage->content);
        $this->assertSame('SD', $importedPage->spaceKey);
        $this->assertSame(17, $importedPage->version);
    }

    /**
     * What: Confluence HTML content is converted into the expected Markdown representation.
     * How: The real conversion pipeline processes a heading and bold paragraph for exact comparison.
     * Why: Imported pages must be usable as Markdown rather than retaining Confluence HTML.
     */
    public function testConvertsHtmlContentToMarkdown(): void
    {
        // arrange
        $converter = $this->createConfluenceContentConverter();
        // act
        $result = $converter->toMarkdown(
            '<h2>Heading</h2><p>Hello <strong>world</strong></p>',
        );
        // assert
        $this->assertSame(
            "## Heading\n\nHello **world**",
            trim($result),
        );
    }

    /**
     * What: Confluence attachments are converted into local models.
     * How: The Confluence API is queried for the attachment contents, which are then converted into a local model.
     * Why: Imported pages must be usable as Markdown rather than retaining Confluence HTML.
     * @return void
     * @throws ConfluencePhpClientException
     * @throws Exception
     * @throws \JsonException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testItConvertsAttachmentsToLocalModels(): void
    {
        // arrange
        $contents = "\xFF\xD8\xFFexample-jpeg-data";
        $page = $this->createStub(AbstractContent::class);
        $page->method('getId')->willReturn(123);
        $page->method('getTitle')->willReturn('Page with attachment');
        $page->method('getContent')->willReturn('Content');

        $attachment = new ContentAttachment();
        $attachment
            ->setId('456')
            ->setTitle('photo.jpg');
        $attachment->setDownloadUrl('/download/attachments/123/photo.jpg');
        $attachmentResult = new ContentSearchResult()
            ->setResults([$attachment]);

        $confluenceClient = $this->createMock(ConfluenceClient::class);
        $confluenceClient
            ->expects(self::once())
            ->method('getPage')
            ->with(123)
            ->willReturn($page);
        $confluenceClient
            ->expects(self::once())
            ->method('getPageAttachments')
            ->with($page)
            ->willReturn($attachmentResult);
        $confluenceClient
            ->expects(self::once())
            ->method('downloadAttachment')
            ->with($attachment)
            ->willReturn($contents);

        // act
        $importedPage = new ConfluenceImporter(
            $confluenceClient,
            $this->createConfluenceContentConverter(),
            new ConfluenceLinkedPageExtractor('https://example.atlassian.net/wiki/'),
        )->importPage(123, true);

        // assert
        self::assertCount(1, $importedPage->attachments);
        self::assertSame(
            [ImportedAttachment::class],
            array_map(get_debug_type(...), $importedPage->attachments),
        );
        self::assertNotContains($attachment, $importedPage->attachments);
        self::assertSame('456', $importedPage->attachments[0]->id);
        self::assertSame('photo.jpg', $importedPage->attachments[0]->filename);
        self::assertSame($contents, $importedPage->attachments[0]->content);
    }

    private function createConfluenceContentConverter(): ConfluenceContentConverter
    {
        return ConfluenceContentConverterFactory::create();
    }
}
