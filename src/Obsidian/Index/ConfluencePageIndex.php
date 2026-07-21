<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

final readonly class ConfluencePageIndex
{
    /** @var array<string, IndexedConfluencePage> */
    private array $pages;

    /**
     * @param list<IndexedConfluencePage> $pages
     * @param list<DuplicateConfluencePage> $duplicates
     */
    public function __construct(
        array $pages,
        private array $duplicates,
    ) {
        $indexed = [];

        foreach ($pages as $page) {
            $indexed[$this->key($page->pageId)] = $page;
        }

        $this->pages = $indexed;
    }

    public function find(string $pageId): ?IndexedConfluencePage
    {
        return $this->pages[$this->key($pageId)] ?? null;
    }

    /** @return list<IndexedConfluencePage> */
    public function pages(): array
    {
        return array_values($this->pages);
    }

    /** @return list<DuplicateConfluencePage> */
    public function duplicates(): array
    {
        return $this->duplicates;
    }

    private function key(string $pageId): string
    {
        return 'page:' . $pageId;
    }
}
