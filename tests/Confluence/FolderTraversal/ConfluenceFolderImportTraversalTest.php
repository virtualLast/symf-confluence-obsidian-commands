<?php

declare(strict_types=1);

namespace App\Tests\Confluence\FolderTraversal;

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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ConfluenceFolderImportTraversalTest extends TestCase
{
    public function testItReportsAnEmptyRootFolder(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway->method('getFolder')->willReturn(new ConfluenceFolder('1', 'Engineering', []));
        $pipeline = $this->createMock(ConfluencePageImportPipelineInterface::class);
        $pipeline->expects(self::never())->method('import');

        $result = $this->traversal($pipeline, $gateway)->traverse(
            '1',
            'Inbox/Confluence',
        );

        self::assertSame(1, $result->foldersVisited);
        self::assertSame(0, $result->pagesDiscovered);
        self::assertSame(0, $result->pagesImported());
        self::assertSame(0, $result->maximumFolderDepth);
        self::assertFalse($result->hasFailures());
        self::assertSame('Inbox/Confluence/Engineering', $result->visitedFolders[0]->destination);
    }

    public function testItPreservesNestedFoldersDeduplicatesPagesAndCollectsStatistics(): void
    {
        $folders = [
            '1' => new ConfluenceFolder('1', 'Engineering', [
                $this->item('10', 'Overview', ConfluenceFolderItemType::Page),
                $this->item('2', 'Authentication', ConfluenceFolderItemType::Folder),
                $this->item('3', 'Other', ConfluenceFolderItemType::Folder),
            ]),
            '2' => new ConfluenceFolder('2', 'Authentication', [
                $this->item('11', 'SSO', ConfluenceFolderItemType::Page),
                $this->item('4', 'SSO & Identity', ConfluenceFolderItemType::Folder),
            ]),
            '3' => new ConfluenceFolder('3', 'Other', [
                $this->item('10', 'Overview', ConfluenceFolderItemType::Page),
            ]),
            '4' => new ConfluenceFolder('4', 'SSO & Identity', [
                $this->item('12', 'Deep Overview', ConfluenceFolderItemType::Page),
            ]),
        ];
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway
            ->method('getFolder')
            ->willReturnCallback(static fn(string $id): ConfluenceFolder => $folders[$id]);
        $imports = [];
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(function (
                string $id,
                string $destination,
                bool $withAttachments,
            ) use (&$imports): CompletedConfluencePageImport {
                $imports[] = [$id, $destination, $withAttachments];

                return $this->completed(
                    $id,
                    $id === '11'
                        ? [new ImportedAttachment('a', 'a.txt', 'content')]
                        : [],
                );
            });

        $result = $this->traversal($pipeline, $gateway)->traverse(
            '1',
            'Inbox/Confluence',
            true,
        );

        self::assertSame([
            ['10', 'Inbox/Confluence/Engineering', true],
            ['11', 'Inbox/Confluence/Engineering/Authentication', true],
            ['12', 'Inbox/Confluence/Engineering/Authentication/SSO-Identity', true],
        ], $imports);
        self::assertSame(4, $result->foldersVisited);
        self::assertSame(4, $result->pagesDiscovered);
        self::assertSame(3, $result->pagesImported());
        self::assertSame(0, $result->pagesFailed());
        self::assertSame(1, $result->duplicatePages());
        self::assertSame(2, $result->maximumFolderDepth);
        self::assertSame(1, $result->attachmentsDownloaded);
    }

    public function testIndependentDescendantsContinueAfterFolderAndPageFailures(): void
    {
        $folders = [
            '1' => new ConfluenceFolder('1', 'Root', [
                $this->item('2', 'Broken folder', ConfluenceFolderItemType::Folder),
                $this->item('3', 'Working folder', ConfluenceFolderItemType::Folder),
                $this->item('10', 'Broken page', ConfluenceFolderItemType::Page),
                $this->item('11', 'Working page', ConfluenceFolderItemType::Page),
            ]),
            '3' => new ConfluenceFolder('3', 'Working folder', [
                $this->item('12', 'Descendant page', ConfluenceFolderItemType::Page),
            ]),
        ];
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway
            ->method('getFolder')
            ->willReturnCallback(static function (string $id) use ($folders): ConfluenceFolder {
                if ($id === '2') {
                    throw new RuntimeException('Folder unavailable.');
                }

                return $folders[$id];
            });
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(function (string $id): CompletedConfluencePageImport {
                if ($id === '10') {
                    throw new RuntimeException('Page unavailable.');
                }

                return $this->completed($id);
            });

        $result = $this->traversal($pipeline, $gateway)->traverse('1', 'Import');

        self::assertSame(3, $result->foldersVisited);
        self::assertSame(3, $result->pagesDiscovered);
        self::assertSame(2, $result->pagesImported());
        self::assertSame(1, $result->pagesFailed());
        self::assertCount(2, $result->failures);
        self::assertTrue($result->hasFailures());
    }

    public function testRepeatedFoldersDoNotCreateACycle(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway
            ->method('getFolder')
            ->willReturnCallback(fn(string $id): ConfluenceFolder => $id === '1'
                ? new ConfluenceFolder('1', 'Root', [
                    $this->item('2', 'Child', ConfluenceFolderItemType::Folder),
                ])
                : new ConfluenceFolder('2', 'Child', [
                    $this->item('1', 'Root', ConfluenceFolderItemType::Folder),
                ]));
        $pipeline = $this->createMock(ConfluencePageImportPipelineInterface::class);
        $pipeline->expects(self::never())->method('import');

        $result = $this->traversal($pipeline, $gateway)->traverse('1', 'Import');

        self::assertSame(2, $result->foldersVisited);
        self::assertSame(1, $result->maximumFolderDepth);
    }

    public function testPagesActAsContainersForMixedPageAndFolderChildren(): void
    {
        $folders = [
            '1' => new ConfluenceFolder('1', 'Root', [
                $this->item('10', 'Projects', ConfluenceFolderItemType::Page),
            ]),
            '2' => new ConfluenceFolder('2', 'SSO', [
                $this->item('12', 'SSO Overview', ConfluenceFolderItemType::Page),
            ]),
        ];
        $pageChildren = [
            '10' => [
                $this->item('11', 'Ops Automation', ConfluenceFolderItemType::Page),
                $this->item('2', 'SSO', ConfluenceFolderItemType::Folder),
            ],
            '11' => [
                $this->item('13', 'Runbook', ConfluenceFolderItemType::Page),
            ],
            '12' => [],
            '13' => [],
        ];
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway
            ->method('getFolder')
            ->willReturnCallback(static fn(string $id): ConfluenceFolder => $folders[$id]);
        $gateway
            ->method('getPageDirectChildren')
            ->willReturnCallback(static fn(string $id): array => $pageChildren[$id]);
        $imports = [];
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(function (
                string $id,
                string $destination,
            ) use (&$imports): CompletedConfluencePageImport {
                $imports[] = [$id, $destination];

                return $this->completed($id);
            });

        $result = $this->traversal($pipeline, $gateway)->traverse('1', 'Import');

        self::assertSame([
            ['10', 'Import/Root'],
            ['11', 'Import/Root/Projects'],
            ['13', 'Import/Root/Projects/Ops-Automation'],
            ['12', 'Import/Root/Projects/SSO'],
        ], $imports);
        self::assertSame(2, $result->foldersVisited);
        self::assertSame(4, $result->pagesDiscovered);
        self::assertSame(4, $result->pagesImported());
        self::assertSame(2, $result->maximumFolderDepth);
        self::assertSame(0, $result->pageChildDiscoveryFailures());
    }

    public function testPageChildDiscoveryFailureDoesNotStopIndependentContent(): void
    {
        $gateway = $this->createStub(ConfluenceGatewayInterface::class);
        $gateway->method('getFolder')->willReturn(new ConfluenceFolder('1', 'Root', [
            $this->item('10', 'Broken children', ConfluenceFolderItemType::Page),
            $this->item('11', 'Working', ConfluenceFolderItemType::Page),
        ]));
        $gateway
            ->method('getPageDirectChildren')
            ->willReturnCallback(static function (string $id): array {
                if ($id === '10') {
                    throw new RuntimeException('Children unavailable.');
                }

                return [];
            });
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(fn(string $id): CompletedConfluencePageImport => $this->completed($id));

        $result = $this->traversal($pipeline, $gateway)->traverse('1', 'Import');

        self::assertSame(2, $result->pagesImported());
        self::assertSame(1, $result->pageChildDiscoveryFailures());
        self::assertTrue($result->hasFailures());
    }

    private function traversal(
        ConfluencePageImportPipelineInterface $pipeline,
        ConfluenceGatewayInterface $gateway,
    ): ConfluenceFolderImportTraversal {
        return new ConfluenceFolderImportTraversal(
            $pipeline,
            $gateway,
            new ConfluenceFolderDestinationBuilder(new AsciiSlugger()),
        );
    }

    private function item(
        string $id,
        string $title,
        ConfluenceFolderItemType $type,
    ): ConfluenceFolderItem {
        return new ConfluenceFolderItem($id, $title, $type);
    }

    /** @param list<ImportedAttachment> $attachments */
    private function completed(string $id, array $attachments = []): CompletedConfluencePageImport
    {
        return new CompletedConfluencePageImport(
            new ImportedPage($id, 'Page ' . $id, 'Content', attachments: $attachments),
            '/vault/' . $id . '.md',
        );
    }
}
