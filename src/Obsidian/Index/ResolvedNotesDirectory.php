<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

final readonly class ResolvedNotesDirectory
{
    public function __construct(
        public string $vaultPath,
        public string $absolutePath,
        public string $relativePath,
    ) {}
}
