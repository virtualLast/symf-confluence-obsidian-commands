<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

use App\Confluence\CompletedConfluencePageImport;
use App\Model\ConfluenceFolderItem;

final readonly class ImportedConfluenceFolderPage
{
    public function __construct(
        public ConfluenceFolderItem $page,
        public string $destination,
        public int $folderDepth,
        public CompletedConfluencePageImport $completedImport,
    ) {}
}
