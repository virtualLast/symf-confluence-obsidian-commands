<?php

declare(strict_types=1);

namespace App\Confluence\Export;

interface ConfluenceExportGatewayInterface
{
    /** Fetch a published page's current storage body and version. */
    public function getPage(string $pageId): ExportPage;

    /** Replace the body once using the observed version; return the published version. */
    public function publish(ExportPage $page, string $storage): int;
}
