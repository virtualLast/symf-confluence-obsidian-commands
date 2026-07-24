<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ConfluencePage
{
    public function __construct(
        public string $id,
        public string $title,
        public string $html,
        public string $spaceId,
        public string $spaceKey,
        public int $version,
    ) {}
}
