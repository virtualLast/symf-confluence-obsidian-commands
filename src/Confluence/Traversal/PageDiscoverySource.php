<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

enum PageDiscoverySource: string
{
    case Root = 'root';
    case Child = 'child';
    case Linked = 'linked';
}
