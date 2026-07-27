<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

use App\Model\ConfluenceFolder;

final readonly class VisitedConfluenceFolder
{
    public function __construct(
        public QueuedConfluenceFolder $queuedFolder,
        public ConfluenceFolder $folder,
        public string $destination,
    ) {}
}
