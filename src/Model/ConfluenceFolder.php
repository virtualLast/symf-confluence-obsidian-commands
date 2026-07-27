<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ConfluenceFolder
{
    /** @param list<ConfluenceFolderItem> $items */
    public function __construct(
        public string $id,
        public string $title,
        public array $items,
    ) {}
}
