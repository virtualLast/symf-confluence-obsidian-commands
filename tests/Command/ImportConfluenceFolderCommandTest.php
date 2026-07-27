<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportConfluenceFolderCommand;
use App\Confluence\CompletedConfluencePageImport;
use App\Confluence\ConfluenceGatewayInterface;
use App\Confluence\ConfluencePageImportPipelineInterface;
use App\Confluence\FolderTraversal\ConfluenceFolderDestinationBuilder;
use App\Confluence\FolderTraversal\ConfluenceFolderImportTraversal;
use App\Model\ConfluenceFolder;
use App\Model\ConfluenceFolderItem;
use App\Model\ConfluenceFolderItemType;
use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ImportConfluenceFolderCommandTest extends TestCase
{
    public function testItImportsAFolderWithAttachmentsAndRendersVerboseSummary(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway->method('getFolder')->willReturn(new ConfluenceFolder(
            '404717571',
            'Engineering',
            [
                new ConfluenceFolderItem(
                    '10',
                    'Overview',
                    ConfluenceFolderItemType::Page,
                ),
            ],
        ));
        $pipeline = $this->createMock(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->expects(self::once())
            ->method('import')
            ->with('10', 'Inbox/Confluence/Engineering', true)
            ->willReturn(new CompletedConfluencePageImport(
                new ImportedPage(
                    '10',
                    'Overview',
                    'Content',
                    attachments: [new ImportedAttachment('1', 'one.txt', 'one')],
                ),
                '/vault/Inbox/Confluence/Engineering/overview.md',
            ));

        $tester = $this->tester($pipeline, $gateway);
        $exitCode = $tester->execute([
            'folder' => '404717571',
            'destination' => 'Inbox/Confluence',
            '--with-attachments' => true,
        ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[folder] Engineering (404717571)', $display);
        self::assertStringContainsString('[imported] Overview (10)', $display);
        self::assertMatchesRegularExpression('/Folders visited\s+1/', $display);
        self::assertMatchesRegularExpression('/Pages discovered\s+1/', $display);
        self::assertMatchesRegularExpression('/Pages imported\s+1/', $display);
        self::assertMatchesRegularExpression('/Attachments downloaded\s+1/', $display);
    }

    public function testItReturnsFailureAfterReportingAPartialImport(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway->method('getFolder')->willReturn(new ConfluenceFolder(
            '1',
            'Root',
            [
                new ConfluenceFolderItem('10', 'Broken', ConfluenceFolderItemType::Page),
                new ConfluenceFolderItem('11', 'Working', ConfluenceFolderItemType::Page),
            ],
        ));
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(static function (string $id): CompletedConfluencePageImport {
                if ($id === '10') {
                    throw new RuntimeException('Import failed.');
                }

                return new CompletedConfluencePageImport(
                    new ImportedPage('11', 'Working', 'Content'),
                    '/vault/working.md',
                );
            });

        $tester = $this->tester($pipeline, $gateway);
        $exitCode = $tester->execute([
            'folder' => '1',
            'destination' => 'Import',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('1 page import(s)', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Pages imported\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Pages failed\s+1/', $tester->getDisplay());
    }

    #[DataProvider('invalidFolderIds')]
    public function testItRejectsAnInvalidFolderId(string $folderId): void
    {
        $pipeline = $this->createMock(ConfluencePageImportPipelineInterface::class);
        $pipeline->expects(self::never())->method('import');
        $gateway = $this->createMock(ConfluenceGatewayInterface::class);
        $gateway->expects(self::never())->method('getFolder');

        $tester = $this->tester($pipeline, $gateway);
        $exitCode = $tester->execute([
            'folder' => $folderId,
            'destination' => 'Import',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString(
            'folder ID must be a positive integer',
            $tester->getDisplay(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFolderIds(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'non-numeric' => ['folder'];
    }

    private function tester(
        ConfluencePageImportPipelineInterface $pipeline,
        ConfluenceGatewayInterface $gateway,
    ): CommandTester {
        return new CommandTester(new ImportConfluenceFolderCommand(
            new ConfluenceFolderImportTraversal(
                $pipeline,
                $gateway,
                new ConfluenceFolderDestinationBuilder(new AsciiSlugger()),
            ),
        ));
    }
}
