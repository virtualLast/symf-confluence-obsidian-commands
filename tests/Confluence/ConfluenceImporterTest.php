<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceContentConverter;
use App\Confluence\ConfluenceGatewayInterface;
use App\Confluence\ConfluenceImporter;
use App\Confluence\ConfluenceLinkedPageExtractor;
use App\Model\ConfluenceAttachment;
use App\Model\ConfluencePage;
use App\Model\ImportedAttachment;
use PHPUnit\Framework\TestCase;

final class ConfluenceImporterTest extends TestCase
{
    public function testItMapsOnlyLocalConfluenceModelsIntoAnImportedPage(): void
    {
        $gateway = $this->createMock(ConfluenceGatewayInterface::class);
        $gateway
            ->expects(self::once())
            ->method('getPage')
            ->with('664600597')
            ->willReturn(new ConfluencePage(
                id: '664600597',
                title: 'Test page',
                html: '<p>Test content</p>',
                spaceId: '1234',
                spaceKey: 'SD',
                version: 17,
            ));
        $gateway->expects(self::never())->method('getPageAttachments');

        $importedPage = $this->importer($gateway)->importPage('664600597');

        self::assertSame('664600597', $importedPage->id);
        self::assertSame('Test page', $importedPage->title);
        self::assertSame('Test content', $importedPage->content);
        self::assertSame('1234', $importedPage->spaceId);
        self::assertSame('SD', $importedPage->spaceKey);
        self::assertSame(17, $importedPage->version);
    }

    public function testItConvertsAttachmentsToImportedAttachmentModels(): void
    {
        $contents = "\xFF\xD8\xFFexample-jpeg-data";
        $gateway = $this->createMock(ConfluenceGatewayInterface::class);
        $gateway
            ->method('getPage')
            ->willReturn(new ConfluencePage('123', 'Page', '<p>Content</p>', '42', 'SD', 1));
        $gateway
            ->expects(self::once())
            ->method('getPageAttachments')
            ->with('123')
            ->willReturn([
                new ConfluenceAttachment(
                    id: 'att456',
                    filename: 'photo.jpg',
                    content: $contents,
                    mediaType: 'image/jpeg',
                    size: strlen($contents),
                    downloadUrl: '/wiki/download/photo.jpg',
                ),
            ]);

        $importedPage = $this->importer($gateway)->importPage('123', true);

        self::assertCount(1, $importedPage->attachments);
        self::assertInstanceOf(ImportedAttachment::class, $importedPage->attachments[0]);
        self::assertSame('att456', $importedPage->attachments[0]->id);
        self::assertSame('photo.jpg', $importedPage->attachments[0]->filename);
        self::assertSame($contents, $importedPage->attachments[0]->content);
        self::assertSame('/wiki/download/photo.jpg', $importedPage->attachments[0]->downloadUrl);
    }

    public function testItExtractsLinkedPagesFromTheLocalPageHtml(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway->method('getPage')->willReturn(new ConfluencePage(
            '123',
            'Page',
            '<a href="https://example.atlassian.net/wiki/pages/viewpage.action?pageId=456">Target</a>',
            '42',
            'SD',
            1,
        ));

        $page = $this->importer($gateway)->importPage('123');

        self::assertCount(1, $page->linkedPages);
        self::assertSame('456', $page->linkedPages[0]->id);
    }

    private function importer(ConfluenceGatewayInterface $gateway): ConfluenceImporter
    {
        return new ConfluenceImporter(
            $gateway,
            $this->createConfluenceContentConverter(),
            new ConfluenceLinkedPageExtractor('https://example.atlassian.net/wiki/'),
        );
    }

    private function createConfluenceContentConverter(): ConfluenceContentConverter
    {
        return ConfluenceContentConverterFactory::create();
    }
}
