<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final readonly class DuplicateConfluenceDiscovery
{
    public function __construct(
        public QueuedConfluencePage $page,
    ) {}
}
