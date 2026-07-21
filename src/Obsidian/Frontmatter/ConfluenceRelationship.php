<?php

declare(strict_types=1);

namespace App\Obsidian\Frontmatter;

final readonly class ConfluenceRelationship
{
    public function __construct(
        public string $pageId,
        public string $title,
        public string $url,
    ) {}
}
