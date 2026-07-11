<?php

namespace App\Confluence;

use App\Model\ImportedPage;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;

readonly class ConfluenceImporter
{
    public function __construct(
        private ConfluenceClient $confluenceClient,
        private ConfluenceContentConverter $confluenceContentConverter,
    ) {
    }

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function importPage(int $pageId, bool $includeChildren = false): ImportedPage|ContentSearchResult
    {
        $page = $this->confluenceClient->getPage($pageId);

        if ($includeChildren) {
            return $this->confluenceClient->getPageChildren($page);
        }

        $markdown = $this->confluenceContentConverter->toMarkdown($page->getContent());

        return new ImportedPage(
            $page->getId(),
            $page->getTitle(),
            $markdown,
        );
    }
}
