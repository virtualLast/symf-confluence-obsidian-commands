<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

final readonly class QueuedConfluenceFolder
{
    public function __construct(
        public string $folderId,
        public string $parentDestination,
        public int $depth,
    ) {}
}
