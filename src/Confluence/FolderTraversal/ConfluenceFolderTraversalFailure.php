<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

final readonly class ConfluenceFolderTraversalFailure
{
    public function __construct(
        public FolderTraversalFailureType $type,
        public string $contentId,
        public ?string $knownTitle,
        public string $destination,
        public int $folderDepth,
        public string $message,
    ) {}
}
