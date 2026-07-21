<?php

declare(strict_types=1);

namespace App\Obsidian\Link;

final readonly class UnresolvedConfluenceRelationship
{
    public function __construct(
        public string $sourcePath,
        public string $pageId,
    ) {}
}
