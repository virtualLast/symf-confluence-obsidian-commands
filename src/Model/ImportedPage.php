<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ImportedPage
{
    /** @param ImportedAttachment[] $attachments */
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public array $attachments = [],
    ) {}
}
