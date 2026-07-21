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
        public int $pagesScheduled,
        public array $importedPages,
        public array $failures,
        public array $duplicates,
        public int $childPagesFound,
        public int $linkedPagesFound,
        public int $maximumDepthReached,
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

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
