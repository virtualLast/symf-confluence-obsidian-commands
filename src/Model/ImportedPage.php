<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ImportedPage
{
    /**
     * @param list<ImportedAttachment> $attachments
     * @param list<ImportedLinkedPage> $linkedPages
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $content,
        public array $attachments = [],
        public string $spaceId = '',
        public string $spaceKey = '',
        public int $version = 1,
        public array $linkedPages = [],
    ) {}
}
