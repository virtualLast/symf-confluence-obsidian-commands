<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfluencePageUrl
{
    public function __construct(
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private string $baseUrl,
    ) {}

    /** Build a page URL on the configured site, independent of title and space. */
    public function forPage(string $pageId): string
    {
        return rtrim(trim($this->baseUrl), '/') . '/wiki/pages/viewpage.action?pageId=' . rawurlencode($pageId);
    }
}
