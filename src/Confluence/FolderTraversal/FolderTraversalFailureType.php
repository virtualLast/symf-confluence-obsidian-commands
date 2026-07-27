<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

enum FolderTraversalFailureType: string
{
    case FolderRetrieval = 'folder-retrieval';
    case PageImport = 'page-import';
    case PageChildDiscovery = 'page-child-discovery';
}
