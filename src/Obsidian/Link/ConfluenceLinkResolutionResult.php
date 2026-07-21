<?php

declare(strict_types=1);

namespace App\Obsidian\Link;

final readonly class ConfluenceLinkResolutionResult
{
    /** @param list<UnresolvedConfluenceRelationship> $unresolved */
    public function __construct(
        public string $content,
        public int $relationshipsChecked,
        public int $linksResolved,
        public array $unresolved,
        public bool $changed,
    ) {}
}
