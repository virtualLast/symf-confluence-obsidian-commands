<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExportConfluenceCommand;
use App\Confluence\ConfluenceRequestException;
use App\Confluence\Export\ConfluenceAttachmentPublisherInterface;
use App\Confluence\Export\ConfluenceExportGatewayInterface;
use App\Confluence\Export\ConfluencePageUrl;
use App\Confluence\Export\ExportContentConverter;
use App\Confluence\Export\ExportLinkResolver;
use App\Confluence\Export\ExportNoteReader;
use App\Confluence\Export\ExportPage;
use App\Obsidian\Frontmatter\FrontmatterParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ExportConfluenceCommandTest extends TestCase
{
    private string $vault;

    protected function setUp(): void
    {
        $this->vault = sys_get_temp_dir() . '/confluence-command-' . bin2hex(random_bytes(8));
        mkdir($this->vault);
        file_put_contents($this->vault . '/note.md', "---\nsource: confluence\nconfluence:\n  pageId: '123'\n  version: 1\n---\n# Local\n\n![[document.pdf]]");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->vault);
    }

    public function testPreviewShowsBothBodiesAndWarningsWithoutPublishing(): void
    {
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::once())->method('getPage')->with('123')->willReturn($this->page());
        $gateway->expects(self::never())->method('publish');
        $tester = $this->tester($gateway);
        self::assertSame(0, $tester->execute(['note' => 'note.md']));
        foreach (['Remote title', 'pageId=123', 'Current version: 9', '<ac:structured-macro', '<h1>Local</h1>', 'Skipped non-image embed: document.pdf', 'Preview only', 'will be replaced'] as $expected) {
            self::assertStringContainsString($expected, $tester->getDisplay());
        }
    }

    public function testPublishUsesCurrentRemoteVersionAndDoesNotChangeLocalFile(): void
    {
        $before = file_get_contents($this->vault . '/note.md');
        $page = $this->page();
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->method('getPage')->willReturn($page);
        $gateway->expects(self::once())->method('publish')->with($page, self::stringContains('<h1>Local</h1>'))->willReturn(10);
        $tester = $this->tester($gateway);
        self::assertSame(0, $tester->execute(['note' => 'note.md', '--publish' => true, '--vault' => $this->vault]));
        self::assertStringContainsString('Published version 10', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->vault . '/note.md'));
    }

    public function testFailedPublicationReturnsFailureAndDoesNotRetry(): void
    {
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->method('getPage')->willReturn($this->page());
        $gateway->expects(self::once())->method('publish')->willThrowException(new ConfluenceRequestException('Concurrent update', 409));
        $tester = $this->tester($gateway);
        self::assertSame(1, $tester->execute(['note' => 'note.md', '--publish' => true]));
        self::assertStringContainsString('Concurrent update', $tester->getDisplay());
        self::assertStringNotContainsString('Published version', $tester->getDisplay());
    }

    public function testInvalidLocalContentFailsBeforeContactingConfluence(): void
    {
        file_put_contents($this->vault . '/note.md', "\n[[missing]]", FILE_APPEND);
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::never())->method('getPage');
        $gateway->expects(self::never())->method('publish');
        self::assertSame(1, $this->tester($gateway)->execute(['note' => 'note.md', '--publish' => true]));
    }

    public function testImagePreviewNeverUploads(): void
    {
        $this->addImage();
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::once())->method('getPage')->willReturn($this->page());
        $gateway->expects(self::never())->method('publish');
        $attachments = $this->createMock(ConfluenceAttachmentPublisherInterface::class);
        $attachments->expects(self::never())->method('publish');
        $tester = $this->tester($gateway, $attachments);
        self::assertSame(0, $tester->execute(['note' => 'note.md']));
        self::assertStringContainsString('Upload/update image: image.png', $tester->getDisplay());
    }

    public function testAttachmentsAreUploadedOnceBeforePagePublication(): void
    {
        $this->addImage();
        file_put_contents($this->vault . '/note.md', "\n![[image.png]]", FILE_APPEND);
        $events = [];
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::once())->method('getPage')->willReturn($this->page());
        $gateway->expects(self::once())->method('publish')->willReturnCallback(static function () use (&$events): int {
            $events[] = 'page';
            return 10;
        });
        $attachments = $this->createMock(ConfluenceAttachmentPublisherInterface::class);
        $attachments->expects(self::once())->method('publish')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'image';
        });
        self::assertSame(0, $this->tester($gateway, $attachments)->execute(['note' => 'note.md', '--publish' => true]));
        self::assertSame(['image', 'page'], $events);
    }

    public function testAttachmentFailureStopsBeforeThePageWriteAndReportsPartialOutcome(): void
    {
        $this->addImage();
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::once())->method('getPage')->willReturn($this->page());
        $gateway->expects(self::never())->method('publish');
        $attachments = $this->createMock(ConfluenceAttachmentPublisherInterface::class);
        $attachments->expects(self::once())->method('publish')->willThrowException(new ConfluenceRequestException('Upload failed'));
        $tester = $this->tester($gateway, $attachments);
        self::assertSame(1, $tester->execute(['note' => 'note.md', '--publish' => true]));
        self::assertStringContainsString('Some image attachments may already have been updated', $tester->getDisplay());
    }

    public function testPageFailureAfterUploadReportsPossibleAttachmentChanges(): void
    {
        $this->addImage();
        $gateway = $this->createMock(ConfluenceExportGatewayInterface::class);
        $gateway->expects(self::once())->method('getPage')->willReturn($this->page());
        $gateway->expects(self::once())->method('publish')->willThrowException(new ConfluenceRequestException('Concurrent page edit', 409));
        $attachments = $this->createMock(ConfluenceAttachmentPublisherInterface::class);
        $attachments->expects(self::once())->method('publish');
        $tester = $this->tester($gateway, $attachments);
        self::assertSame(1, $tester->execute(['note' => 'note.md', '--publish' => true]));
        self::assertStringContainsString('Some image attachments may already have been updated', $tester->getDisplay());
        self::assertStringNotContainsString('Published version', $tester->getDisplay());
    }

    private function addImage(): void
    {
        file_put_contents($this->vault . '/image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true));
        file_put_contents($this->vault . '/note.md', "\n![[image.png]]", FILE_APPEND);
    }

    private function page(): ExportPage
    {
        return new ExportPage('123', 'Remote title', '<p>Remote edit</p><ac:structured-macro ac:name="toc" />', 9);
    }

    private function tester(ConfluenceExportGatewayInterface $gateway, ?ConfluenceAttachmentPublisherInterface $attachments = null): CommandTester
    {
        $reader = new ExportNoteReader(new FrontmatterParser(), $this->vault);
        $url = new ConfluencePageUrl('https://example.atlassian.net');

        return new CommandTester(new ExportConfluenceCommand($reader, new ExportContentConverter(new ExportLinkResolver($reader, $url)), $gateway, $url, $attachments ?? $this->createStub(ConfluenceAttachmentPublisherInterface::class)));
    }
}
