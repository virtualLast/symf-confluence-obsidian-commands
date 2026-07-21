<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ConfluencePageReference
{
    public function __construct(
        public string $id,
        public ?string $title = null,
    ) {}
}
