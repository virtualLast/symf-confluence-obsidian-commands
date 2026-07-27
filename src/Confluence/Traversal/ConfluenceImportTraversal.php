<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

use App\Confluence\ConfluenceGatewayInterface;
use App\Confluence\ConfluencePageImportPipelineInterface;
use SplQueue;

final readonly class ConfluenceImportTraversal
{
    public function __construct(
        private ConfluencePageImportPipelineInterface $importPipeline,
        private ConfluenceGatewayInterface $confluenceGateway,
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
        $scheduled = new ScheduledConfluencePages();
        $scheduled->schedule($rootPageId);
        $importedPages = [];
        $failures = [];
        $duplicates = [];
        $childRelationshipsDiscovered = 0;
        $linkedPageRelationshipsDiscovered = 0;
        $maximumDepthReached = 0;
        $attachmentsDownloaded = 0;
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
            $attachmentsDownloaded += count($completedImport->page->attachments);

            if (!$options->canExpand($queuedPage->depth)) {
                continue;
            }

            if ($options->followChildren) {
                try {
                    $children = $this->confluenceGateway->getChildPages($completedImport->page->id);
                    $childRelationshipsDiscovered += count($children);

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
                $linkedPageRelationshipsDiscovered += count($completedImport->page->linkedPages);

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
            1,
            $scheduled->count(),
            $importedPages,
            $failures,
            $duplicates,
            $childRelationshipsDiscovered,
            $linkedPageRelationshipsDiscovered,
            $maximumDepthReached,
            $options->followChildren,
            $options->followLinks,
            $options->maximumDepth,
            $withAttachments,
            $attachmentsDownloaded,
            $rootFailed,
        );
    }

    /**
     * @param SplQueue<QueuedConfluencePage> $queue
     * @param list<DuplicateConfluenceDiscovery> $duplicates
     */
    private function schedule(
        SplQueue $queue,
        ScheduledConfluencePages $scheduled,
        array &$duplicates,
        QueuedConfluencePage $page,
    ): void {
        if (!$scheduled->schedule($page->pageId)) {
            $duplicates[] = new DuplicateConfluenceDiscovery($page);

            return;
        }
        $queue->enqueue($page);
    }
}
