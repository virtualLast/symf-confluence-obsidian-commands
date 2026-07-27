<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

final readonly class ConfluenceFolderTraversalResult
{
    /**
     * @param list<VisitedConfluenceFolder> $visitedFolders
     * @param list<ImportedConfluenceFolderPage> $importedPages
     * @param list<DuplicateConfluenceFolderPage> $duplicates
     * @param list<ConfluenceFolderTraversalFailure> $failures
     */
    public function __construct(
        public int $foldersVisited,
        public int $pagesDiscovered,
        public array $visitedFolders,
        public array $importedPages,
        public array $duplicates,
        public array $failures,
        public int $maximumFolderDepth,
        public int $attachmentsDownloaded,
    ) {}

    public function pagesImported(): int
    {
        return count($this->importedPages);
    }

    public function pagesFailed(): int
    {
        return count(array_filter(
            $this->failures,
            static fn(ConfluenceFolderTraversalFailure $failure): bool => $failure->type === FolderTraversalFailureType::PageImport,
        ));
    }

    public function duplicatePages(): int
    {
        return count($this->duplicates);
    }

    public function pageChildDiscoveryFailures(): int
    {
        return count(array_filter(
            $this->failures,
            static fn(ConfluenceFolderTraversalFailure $failure): bool => $failure->type === FolderTraversalFailureType::PageChildDiscovery,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
