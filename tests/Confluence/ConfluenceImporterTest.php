<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluenceImporter;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use PHPUnit\Framework\TestCase;

class ConfluenceImporterTest extends TestCase
{
    /**
     * what: Given includeChildren is false when importing a page,
     *  then the importer fetches the page and does not fetch children
     *  and returns the page
     * why: we need to confirm that we can successfully import a page
     * how: mock a page and client and assert the page mock and imported page are the same
     * @return void
     */
    public function testImportPageFetchesPageFromConfluence(): void
    {
        // arrange
        $pageId = 664600597;
        $pageStub = $this->createStub(AbstractContent::class);
        $confluenceClientMock = $this->createMock(ConfluenceClient::class);

        $confluenceClientMock->expects($this->once())
            ->method('getPage')
            ->with($pageId)
            ->willReturn($pageStub);
        $confluenceClientMock
            ->expects($this->never())
            ->method('getPageChildren');

        // act
        $importer = new ConfluenceImporter($confluenceClientMock);
        $importPage = $importer->importPage($pageId);

        // assert
        $this->assertSame($pageStub, $importPage);
    }

    /**
     * what: Tests that importPageWithChildren fetches page and children from Confluence
     * why: To ensure that the importer correctly retrieves both the page and its children from Confluence
     * how: By mocking the ConfluenceClient and asserting that the expected methods are called with the correct parameters
     * @return void
     */
    public function testImportPageWithChildrenFetchesPageAndChildrenFromConfluence(): void
    {
        // arrange
        $pageId = 664600597;
        $pageStub = $this->createStub(AbstractContent::class);
        $pagesStub = $this->createStub(ContentSearchResult::class);
        $confluenceClientMock = $this->createMock(ConfluenceClient::class);

        $confluenceClientMock
            ->expects($this->once())
            ->method('getPage')
            ->with($pageId)
            ->willReturn($pageStub)
        ;
        $confluenceClientMock
            ->expects($this->once())
            ->method('getPageChildren')
            ->with($pageStub)
            ->willReturn($pagesStub)
        ;

        // act
        $importer = new ConfluenceImporter($confluenceClientMock);
        $importPages = $importer->importPage($pageId, true);

        // assert
        $this->assertSame($pagesStub, $importPages);
    }
}
