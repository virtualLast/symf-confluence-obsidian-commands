<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

final readonly class ConfluencePageIndexResult
{
    /** @param array<string, string> $invalidNotes */
    public function __construct(
        public ConfluencePageIndex $index,
        public int $notesDiscovered,
        public int $notesIndexed,
        public int $notesSkipped,
        public array $invalidNotes,
    ) {}
}
