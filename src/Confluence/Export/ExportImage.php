<?php

declare(strict_types=1);

namespace App\Confluence\Export;

final readonly class ExportImage
{
    /** Capture validated bytes so publication sends exactly the previewed content. */
    public function __construct(
        public string $vaultPath,
        public string $filename,
        public string $mediaType,
        public string $content,
    ) {}
}
