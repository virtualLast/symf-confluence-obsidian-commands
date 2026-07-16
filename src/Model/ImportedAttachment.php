<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ImportedAttachment
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contents,
    ) {}
}
