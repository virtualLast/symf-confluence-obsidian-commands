<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

final readonly class QueuedConfluencePageContainer
{
    public function __construct(
        public string $pageId,
        public string $title,
        public string $parentDestination,
        public int $depth,
    ) {}
}
