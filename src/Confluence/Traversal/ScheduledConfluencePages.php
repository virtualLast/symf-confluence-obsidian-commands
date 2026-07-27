<?php

declare(strict_types=1);

namespace App\Confluence\Traversal;

final class ScheduledConfluencePages
{
    /** @var array<string, true> */
    private array $pageIds = [];

    public function schedule(string $pageId): bool
    {
        $key = 'page:' . $pageId;

        if (isset($this->pageIds[$key])) {
            return false;
        }

        $this->pageIds[$key] = true;

        return true;
    }

    public function count(): int
    {
        return count($this->pageIds);
    }
}
