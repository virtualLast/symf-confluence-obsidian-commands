<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluencePageImportPipelineInterface;
use SplQueue;

final readonly class ConfluenceImportTraversal
{
    public function __construct(
        private ConfluencePageImportPipelineInterface $importPipeline,
        private ConfluenceClient $confluenceClient,
    ) {}

    public function traverse(
        string $rootPageId,
        ConfluenceTraversalOptions $options,
        string $destination = '',
        bool $withAttachments = false,
    ): ConfluenceTraversalResult {
        /** @var SplQueue<QueuedConfluencePage> $queue */
        $queue = new SplQueue();
        $root = new QueuedConfluencePage($rootPageId, 0, PageDiscoverySource::Root);
        $queue->enqueue($root);
        $scheduled = [$this->pageKey($rootPageId) => true];
        $importedPages = [];
        $failures = [];
        $duplicates = [];
        $childPagesFound = 0;
        $linkedPagesFound = 0;
        $maximumDepthReached = 0;
        $rootFailed = false;

        while (!$queue->isEmpty()) {
            $queuedPage = $queue->dequeue();
            $maximumDepthReached = max($maximumDepthReached, $queuedPage->depth);

            try {
                $completedImport = $this->importPipeline->import(
                    $queuedPage->pageId,
                    $destination,
                    $withAttachments,
                );
            } catch (\Exception $exception) {
                $failures[] = new ConfluenceTraversalFailure(
                    TraversalFailureType::Import,
                    $queuedPage,
                    $exception->getMessage(),
                );

                if ($queuedPage->source === PageDiscoverySource::Root) {
                    $rootFailed = true;
                    break;
                }

                continue;
            }

            $importedPages[] = new ImportedTraversalPage($queuedPage, $completedImport);

            if (!$options->canExpand($queuedPage->depth)) {
                continue;
            }

            if ($options->followChildren) {
                try {
                    $children = $this->confluenceClient->getChildPages($completedImport->page->id);
                    $childPagesFound += count($children);

                    foreach ($children as $child) {
                        $this->schedule(
                            $queue,
                            $scheduled,
                            $duplicates,
                            new QueuedConfluencePage(
                                $child->id,
                                $queuedPage->depth + 1,
                                PageDiscoverySource::Child,
                                $completedImport->page->id,
                                $child->title,
                            ),
                        );
                    }
                } catch (\Exception $exception) {
                    $failures[] = new ConfluenceTraversalFailure(
                        TraversalFailureType::ChildDiscovery,
                        $queuedPage,
                        $exception->getMessage(),
                    );
                }
            }

            if ($options->followLinks) {
                $linkedPagesFound += count($completedImport->page->linkedPages);

                foreach ($completedImport->page->linkedPages as $linkedPage) {
                    $this->schedule(
                        $queue,
                        $scheduled,
                        $duplicates,
                        new QueuedConfluencePage(
                            $linkedPage->id,
                            $queuedPage->depth + 1,
                            PageDiscoverySource::Linked,
                            $completedImport->page->id,
                            $linkedPage->title,
                        ),
                    );
                }
            }
        }

        return new ConfluenceTraversalResult(
            count($scheduled),
            $importedPages,
            $failures,
            $duplicates,
            $childPagesFound,
            $linkedPagesFound,
            $maximumDepthReached,
            $rootFailed,
        );
    }

    /**
     * @param SplQueue<QueuedConfluencePage> $queue
     * @param array<string, true> $scheduled
     * @param list<DuplicateConfluenceDiscovery> $duplicates
     */
    private function schedule(
        SplQueue $queue,
        array &$scheduled,
        array &$duplicates,
        QueuedConfluencePage $page,
    ): void {
        $key = $this->pageKey($page->pageId);

        if (isset($scheduled[$key])) {
            $duplicates[] = new DuplicateConfluenceDiscovery($page);

            return;
        }

        $scheduled[$key] = true;
        $queue->enqueue($page);
    }

    private function pageKey(string $pageId): string
    {
        return 'page:' . $pageId;
    }
}
