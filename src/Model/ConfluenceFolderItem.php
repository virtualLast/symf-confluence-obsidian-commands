<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ConfluenceFolderItem
{
    public function __construct(
        public string $id,
        public string $title,
        public ConfluenceFolderItemType $type,
    ) {}
}
