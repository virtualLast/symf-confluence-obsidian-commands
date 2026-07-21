<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final readonly class ConfluenceTraversalOptions
{
    public function __construct(
        public bool $followChildren,
        public bool $followLinks,
        public ?int $maximumDepth = null,
    ) {
        if (!$this->followChildren && !$this->followLinks) {
            throw new \InvalidArgumentException('At least one Confluence traversal strategy must be enabled.');
        }

        if ($this->maximumDepth !== null && $this->maximumDepth < 0) {
            throw new \InvalidArgumentException('The traversal depth must be non-negative.');
        }
    }

    public function canExpand(int $depth): bool
    {
        return $this->maximumDepth === null || $depth < $this->maximumDepth;
    }
}
