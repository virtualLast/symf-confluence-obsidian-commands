<?php

declare(strict_types=1);

namespace App\Confluence;

interface ConfluencePageImportPipelineInterface
{
    public function import(
        string $pageId,
        string $destination = '',
        bool $withAttachments = false,
    ): CompletedConfluencePageImport;
}
