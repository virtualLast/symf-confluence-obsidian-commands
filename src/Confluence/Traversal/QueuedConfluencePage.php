<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final readonly class QueuedConfluencePage
{
    public function __construct(
        public string $pageId,
        public int $depth,
        public PageDiscoverySource $source,
        public ?string $referringPageId = null,
        public ?string $knownTitle = null,
    ) {}
}
