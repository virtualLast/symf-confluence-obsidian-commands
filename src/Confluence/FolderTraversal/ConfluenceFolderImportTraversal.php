<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

use App\Confluence\ConfluenceGatewayInterface;
use App\Confluence\ConfluencePageImportPipelineInterface;
use App\Confluence\Traversal\ScheduledConfluencePages;
use App\Model\ConfluenceFolderItemType;
use SplQueue;

final readonly class ConfluenceFolderImportTraversal
{
    public function __construct(
        private ConfluencePageImportPipelineInterface $importPipeline,
        private ConfluenceGatewayInterface $confluenceGateway,
        private ConfluenceFolderDestinationBuilder $destinationBuilder,
    ) {}

    public function traverse(
        string $rootFolderId,
        string $importRoot,
        bool $withAttachments = false,
    ): ConfluenceFolderTraversalResult {
        /** @var SplQueue<QueuedConfluenceFolder|QueuedConfluencePageContainer> $queue */
        $queue = new SplQueue();
        $queue->enqueue(new QueuedConfluenceFolder($rootFolderId, '', 0));
        $scheduledFolders = ['folder:' . $rootFolderId => true];
        $scheduledPageContainers = [];
        $scheduledPages = new ScheduledConfluencePages();
        $foldersVisited = 0;
        $pagesDiscovered = 0;
        $maximumFolderDepth = 0;
        $attachmentsDownloaded = 0;
        $visitedFolders = [];
        $importedPages = [];
        $duplicates = [];
        $failures = [];

        $discoverItems = function (
            array $items,
            string $parentDestination,
            int $containerDepth,
        ) use (
            $queue,
            &$scheduledFolders,
            &$scheduledPageContainers,
            $scheduledPages,
            &$pagesDiscovered,
            &$attachmentsDownloaded,
            &$importedPages,
            &$duplicates,
            &$failures,
            $importRoot,
            $withAttachments,
        ): void {
            $importDestination = $this->destinationBuilder->importDestination(
                $importRoot,
                $parentDestination,
            );

            foreach ($items as $item) {
                if ($item->type === ConfluenceFolderItemType::Folder) {
                    $key = 'folder:' . $item->id;

                    if (!isset($scheduledFolders[$key])) {
                        $scheduledFolders[$key] = true;
                        $queue->enqueue(new QueuedConfluenceFolder(
                            $item->id,
                            $parentDestination,
                            $containerDepth + 1,
                        ));
                    }

                    continue;
                }

                ++$pagesDiscovered;
                $containerKey = 'page:' . $item->id;

                if (!isset($scheduledPageContainers[$containerKey])) {
                    $scheduledPageContainers[$containerKey] = true;
                    $queue->enqueue(new QueuedConfluencePageContainer(
                        $item->id,
                        $item->title,
                        $parentDestination,
                        $containerDepth + 1,
                    ));
                }

                if (!$scheduledPages->schedule($item->id)) {
                    $duplicates[] = new DuplicateConfluenceFolderPage(
                        $item,
                        $importDestination,
                        $containerDepth,
                    );

                    continue;
                }

                try {
                    $completedImport = $this->importPipeline->import(
                        $item->id,
                        $importDestination,
                        $withAttachments,
                    );
                } catch (\Exception $exception) {
                    $failures[] = new ConfluenceFolderTraversalFailure(
                        FolderTraversalFailureType::PageImport,
                        $item->id,
                        $item->title,
                        $importDestination,
                        $containerDepth,
                        $exception->getMessage(),
                    );

                    continue;
                }

                $importedPages[] = new ImportedConfluenceFolderPage(
                    $item,
                    $importDestination,
                    $containerDepth,
                    $completedImport,
                );
                $attachmentsDownloaded += count($completedImport->page->attachments);
            }
        };

        while (!$queue->isEmpty()) {
            $queuedContent = $queue->dequeue();

            if ($queuedContent instanceof QueuedConfluencePageContainer) {
                try {
                    $items = $this->confluenceGateway->getPageDirectChildren(
                        $queuedContent->pageId,
                    );
                } catch (\Exception $exception) {
                    $failures[] = new ConfluenceFolderTraversalFailure(
                        FolderTraversalFailureType::PageChildDiscovery,
                        $queuedContent->pageId,
                        $queuedContent->title,
                        $queuedContent->parentDestination,
                        $queuedContent->depth,
                        $exception->getMessage(),
                    );

                    continue;
                }

                if ($items !== []) {
                    $discoverItems(
                        $items,
                        $this->destinationBuilder->appendPage(
                            $queuedContent->parentDestination,
                            $queuedContent->title,
                            $queuedContent->pageId,
                        ),
                        $queuedContent->depth,
                    );
                }

                continue;
            }

            $queuedFolder = $queuedContent;
            ++$foldersVisited;
            $maximumFolderDepth = max($maximumFolderDepth, $queuedFolder->depth);

            try {
                $folder = $this->confluenceGateway->getFolder($queuedFolder->folderId);
            } catch (\Exception $exception) {
                $failures[] = new ConfluenceFolderTraversalFailure(
                    FolderTraversalFailureType::FolderRetrieval,
                    $queuedFolder->folderId,
                    null,
                    $queuedFolder->parentDestination,
                    $queuedFolder->depth,
                    $exception->getMessage(),
                );

                continue;
            }

            $folderDestination = $this->destinationBuilder->append(
                $queuedFolder->parentDestination,
                $folder->title,
                $folder->id,
            );
            $importDestination = $this->destinationBuilder->importDestination(
                $importRoot,
                $folderDestination,
            );
            $visitedFolders[] = new VisitedConfluenceFolder(
                $queuedFolder,
                $folder,
                $importDestination,
            );
            $discoverItems(
                $folder->items,
                $folderDestination,
                $queuedFolder->depth,
            );
        }

        return new ConfluenceFolderTraversalResult(
            $foldersVisited,
            $pagesDiscovered,
            $visitedFolders,
            $importedPages,
            $duplicates,
            $failures,
            $maximumFolderDepth,
            $attachmentsDownloaded,
        );
    }
}
