<?php

declare(strict_types=1);

namespace App\Confluence;

final readonly class ConfluenceFolderMetadata
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
