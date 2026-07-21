<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ImportedLinkedPage
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
    ) {}
}
