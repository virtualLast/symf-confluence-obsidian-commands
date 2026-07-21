<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

enum TraversalFailureType: string
{
    case Import = 'import';
    case ChildDiscovery = 'child-discovery';
}
