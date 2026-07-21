<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

final readonly class DuplicateConfluencePage
{
    /** @param list<string> $paths */
    public function __construct(
        public string $pageId,
        public array $paths,
    ) {}
}
