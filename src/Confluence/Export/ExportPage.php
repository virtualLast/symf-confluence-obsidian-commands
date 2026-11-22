<?php

declare(strict_types=1);

namespace App\Confluence\Export;

final readonly class ExportPage
{
    /** Current remote state used for preview and optimistic versioned replacement. */
    public function __construct(
        public string $id,
        public string $title,
        public string $storage,
        public int $version,
    ) {}
}
