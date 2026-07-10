<?php

namespace App\Model;

final readonly class ImportedPage
{
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
    ) {
    }
}
