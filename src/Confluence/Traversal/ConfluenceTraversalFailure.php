<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final readonly class ConfluenceTraversalFailure
{
    public function __construct(
        public TraversalFailureType $type,
        public QueuedConfluencePage $page,
        public string $message,
    ) {}
}
