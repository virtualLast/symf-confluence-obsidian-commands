<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

use App\Confluence\CompletedConfluencePageImport;

final readonly class ImportedTraversalPage
{
    public function __construct(
        public QueuedConfluencePage $queuedPage,
        public CompletedConfluencePageImport $completedImport,
    ) {}
}
