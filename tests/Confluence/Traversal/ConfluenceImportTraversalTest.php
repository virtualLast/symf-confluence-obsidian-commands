<?php

declare(strict_types=1);

namespace App\Tests\Confluence\Traversal;

use App\Confluence\CompletedConfluencePageImport;
use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluencePageImportPipelineInterface;
use App\Confluence\Traversal\ConfluenceImportTraversal;
use App\Confluence\Traversal\ConfluenceTraversalOptions;
use App\Model\ConfluencePageReference;
use App\Model\ImportedLinkedPage;
use App\Model\ImportedPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfluenceImportTraversalTest extends TestCase
{
    public function testItTraversesCombinedRelationshipsBreadthFirstAndDeduplicatesAtEnqueue(): void
    {
        $importOrder = [];
        $pages = [
            'root' => $this->page('root', [new ImportedLinkedPage('linked', 'Linked', 'url')]),
            'child-1' => $this->page('child-1', [new ImportedLinkedPage('linked-child', 'Linked child', 'url')]),
            'child-2' => $this->page('child-2'),
            'linked' => $this->page('linked'),
            'deep-child' => $this->page('deep-child'),
            'linked-child' => $this->page('linked-child'),
        ];
        $children = [
            'root' => [new ConfluencePageReference('child-1'), new ConfluencePageReference('child-2')],
            'child-1' => [new ConfluencePageReference('deep-child')],
            'child-2' => [new ConfluencePageReference('linked-child')],
            'linked' => [new ConfluencePageReference('deep-child')],
        ];

        $result = $this->traversal($pages, $children, $importOrder)->traverse(
            'root',
            new ConfluenceTraversalOptions(true, true, 2),
            'Inbox/Confluence',
            true,
        );

        self::assertSame(
            ['root', 'child-1', 'child-2', 'linked', 'deep-child', 'linked-child'],
            $importOrder,
        );
        self::assertSame(6, $result->pagesScheduled);
        self::assertSame(6, $result->pagesImported());
        self::assertSame(5, $result->childPagesFound);
        self::assertSame(2, $result->linkedPagesFound);
        self::assertCount(2, $result->duplicates);
        self::assertSame(2, $result->maximumDepthReached);
        self::assertFalse($result->hasFailures());
    }

    public function testDepthZeroImportsTheRootWithoutDiscoveringRelationships(): void
    {
        $pipeline = $this->createMock(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->expects(self::once())
            ->method('import')
            ->with('root', '', false)
            ->willReturn($this->completed($this->page('root', [new ImportedLinkedPage('linked', 'Linked', 'url')])));
        $client = $this->createMock(ConfluenceClient::class);
        $client->expects(self::never())->method('getChildPages');

        $result = new ConfluenceImportTraversal($pipeline, $client)->traverse(
            'root',
            new ConfluenceTraversalOptions(true, true, 0),
        );

        self::assertSame(1, $result->pagesScheduled);
        self::assertSame(1, $result->pagesImported());
        self::assertSame(0, $result->childPagesFound);
        self::assertSame(0, $result->linkedPagesFound);
    }

    public function testItPreventsAnUnlimitedLinkedCycle(): void
    {
        $importOrder = [];
        $pages = [
            'a' => $this->page('a', [new ImportedLinkedPage('b', 'B', 'url-b')]),
            'b' => $this->page('b', [new ImportedLinkedPage('a', 'A', 'url-a')]),
        ];

        $result = $this->traversal($pages, [], $importOrder)->traverse(
            'a',
            new ConfluenceTraversalOptions(false, true),
        );

        self::assertSame(['a', 'b'], $importOrder);
        self::assertSame(2, $result->pagesScheduled);
        self::assertCount(1, $result->duplicates);
        self::assertSame('a', $result->duplicates[0]->page->pageId);
    }

    public function testItContinuesIndependentBranchesAfterADescendantFailure(): void
    {
        $importOrder = [];
        $pages = [
            'root' => $this->page('root'),
            'first' => $this->page('first'),
            'last' => $this->page('last'),
        ];
        $children = [
            'root' => [new ConfluencePageReference('first'), new ConfluencePageReference('failed'), new ConfluencePageReference('last')],
        ];

        $result = $this->traversal($pages, $children, $importOrder, ['failed'])->traverse(
            'root',
            new ConfluenceTraversalOptions(true, false),
        );

        self::assertSame(['root', 'first', 'failed', 'last'], $importOrder);
        self::assertSame(3, $result->pagesImported());
        self::assertSame(1, $result->pagesFailed());
        self::assertTrue($result->hasFailures());
        self::assertFalse($result->rootFailed);
    }

    public function testARootFailureAbortsImmediately(): void
    {
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline->method('import')->willThrowException(new RuntimeException('Root unavailable.'));
        $client = $this->createMock(ConfluenceClient::class);
        $client->expects(self::never())->method('getChildPages');

        $result = new ConfluenceImportTraversal($pipeline, $client)->traverse(
            'root',
            new ConfluenceTraversalOptions(true, false),
        );

        self::assertTrue($result->rootFailed);
        self::assertSame(1, $result->pagesScheduled);
        self::assertSame(1, $result->pagesFailed());
        self::assertSame(0, $result->pagesImported());
    }

    public function testAChildDiscoveryFailureStillAllowsLinkedAndQueuedPagesToContinue(): void
    {
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(fn(string $pageId): CompletedConfluencePageImport => $this->completed(
                $pageId === '1'
                    ? $this->page('1', [new ImportedLinkedPage('2', 'Linked', 'url')])
                    : $this->page('2'),
            ));
        $client = $this->createMock(ConfluenceClient::class);
        $client
            ->expects(self::exactly(2))
            ->method('getChildPages')
            ->willReturnCallback(static function (string $pageId): array {
                if ($pageId === '1') {
                    throw new RuntimeException('Children unavailable.');
                }

                return [];
            });

        $result = new ConfluenceImportTraversal($pipeline, $client)->traverse(
            '1',
            new ConfluenceTraversalOptions(true, true),
        );

        self::assertSame(2, $result->pagesImported());
        self::assertSame(1, $result->childDiscoveryFailures());
        self::assertSame(0, $result->pagesFailed());
        self::assertTrue($result->hasFailures());
    }

    /**
     * @param array<string, ImportedPage> $pages
     * @param array<string, list<ConfluencePageReference>> $children
     * @param list<string> $importOrder
     * @param list<string> $failingIds
     */
    private function traversal(
        array $pages,
        array $children,
        array &$importOrder,
        array $failingIds = [],
    ): ConfluenceImportTraversal {
        $pipeline = $this->createStub(ConfluencePageImportPipelineInterface::class);
        $pipeline
            ->method('import')
            ->willReturnCallback(function (string $pageId, string $destination, bool $withAttachments) use ($pages, &$importOrder, $failingIds): CompletedConfluencePageImport {
                $importOrder[] = $pageId;
                self::assertTrue($destination === '' || $destination === 'Inbox/Confluence');
                self::assertTrue($destination === '' || $withAttachments);

                if (in_array($pageId, $failingIds, true)) {
                    throw new RuntimeException('Import failed.');
                }

                return $this->completed($pages[$pageId]);
            });
        $client = $this->createStub(ConfluenceClient::class);
        $client
            ->method('getChildPages')
            ->willReturnCallback(static fn(string $pageId): array => $children[$pageId] ?? []);

        return new ConfluenceImportTraversal($pipeline, $client);
    }

    /** @param list<ImportedLinkedPage> $linkedPages */
    private function page(string $id, array $linkedPages = []): ImportedPage
    {
        return new ImportedPage($id, strtoupper($id), 'Content', linkedPages: $linkedPages);
    }

    private function completed(ImportedPage $page): CompletedConfluencePageImport
    {
        return new CompletedConfluencePageImport($page, '/vault/' . $page->id . '.md');
    }
}
