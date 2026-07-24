<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ConfluenceAttachment
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $content,
        public ?string $mediaType = null,
        public ?int $size = null,
        public ?string $downloadUrl = null,
    ) {}
}
