<?php

declare(strict_types=1);

namespace App\Confluence\Export;

interface ConfluenceAttachmentPublisherInterface
{
    /** Create or update one attachment under its deterministic name, without retrying. */
    public function publish(string $pageId, ExportImage $image): void;
}
