<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

use App\Obsidian\Frontmatter\ConfluenceRelationship;

final readonly class IndexedConfluencePage
{
    /** @param list<ConfluenceRelationship> $relationships */
    public function __construct(
        public string $pageId,
        public string $absolutePath,
        public string $vaultPath,
        public array $relationships,
    ) {}

    public function wikilinkPath(): string
    {
        return substr($this->vaultPath, 0, -3);
    }
}
