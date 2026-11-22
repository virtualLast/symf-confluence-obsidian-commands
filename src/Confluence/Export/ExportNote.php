<?php

declare(strict_types=1);

namespace App\Confluence\Export;

final readonly class ExportNote
{
    /** A validated local note and its original page identity. */
    public function __construct(
        public string $path,
        public string $vault,
        public string $pageId,
        public string $markdown,
    ) {}
}
