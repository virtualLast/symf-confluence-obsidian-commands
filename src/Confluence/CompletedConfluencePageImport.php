<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ImportedPage;

final readonly class CompletedConfluencePageImport
{
    public function __construct(
        public ImportedPage $page,
        public string $filePath,
    ) {}
}
