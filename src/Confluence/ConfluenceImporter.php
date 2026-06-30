<?php

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;

readonly class ConfluenceImporter
{
    public function __construct(
        private ConfluenceClient $confluenceClient,
    ) {
    }

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function importPage(int $pageId, bool $includeChildren = false): AbstractContent|ContentSearchResult
    {
        $page = $this->confluenceClient->getPage($pageId);

        if ($includeChildren) {
            $children = $this->confluenceClient->getPageChildren($page);

            // Temporary: inspect this before writing files.
            dump($children);
        }

        return $page;
    }
}
