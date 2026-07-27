<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final readonly class ConfluenceTraversalResult
{
    /**
     * @param list<ImportedTraversalPage> $importedPages
     * @param list<ConfluenceTraversalFailure> $failures
     * @param list<DuplicateConfluenceDiscovery> $duplicates
     */
    public function __construct(
        public int $rootPages,
        public int $uniquePagesScheduled,
        public array $importedPages,
        public array $failures,
        public array $duplicates,
        public int $childRelationshipsDiscovered,
        public int $linkedPageRelationshipsDiscovered,
        public int $maximumDepthReached,
        public bool $childTraversalEnabled,
        public bool $linkedPageTraversalEnabled,
        public ?int $maximumDepthConfigured,
        public bool $attachmentsRequested,
        public int $attachmentsDownloaded,
        public bool $rootFailed,
    ) {}

    public function pagesImported(): int
    {
        return count($this->importedPages);
    }

    public function pagesFailed(): int
    {
        return count(array_filter(
            $this->failures,
            static fn(ConfluenceTraversalFailure $failure): bool => $failure->type === TraversalFailureType::Import,
        ));
    }

    public function childDiscoveryFailures(): int
    {
        return count(array_filter(
            $this->failures,
            static fn(ConfluenceTraversalFailure $failure): bool => $failure->type === TraversalFailureType::ChildDiscovery,
        ));
    }

    public function duplicateDiscoveries(): int
    {
        return count($this->duplicates);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
