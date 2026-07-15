<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluenceContentConverter;
use App\Confluence\ConfluenceImporter;
use App\Model\ImportedPage;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
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
        $importer = new ConfluenceImporter($confluenceClientMock, $converter);
        $importedPage = $importer->importPage($pageId);

        // assert
        $this->assertInstanceOf(ImportedPage::class, $importedPage);
        $this->assertSame($pageId, $importedPage->id);
        $this->assertSame($title, $importedPage->title);
        $this->assertSame($content, $importedPage->content);
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

    private function createConfluenceContentConverter(): ConfluenceContentConverter
    {
        return ConfluenceContentConverterFactory::create();
    }
}
