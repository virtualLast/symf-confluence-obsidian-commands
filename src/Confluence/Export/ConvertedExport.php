<?php

declare(strict_types=1);

namespace App\Confluence\Export;

final readonly class ConvertedExport
{
    /**
     * @param list<string> $warnings Omitted content to report before publishing.
     * @param list<ExportImage> $images Deduplicated, validated attachments to publish.
     */
    public function __construct(public string $storage, public array $warnings, public array $images = []) {}
}
