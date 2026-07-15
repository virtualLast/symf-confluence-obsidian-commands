<?php

declare(strict_types=1);

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
    ) {}

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function importPage(int $pageId): ImportedPage
    {
        $page = $this->confluenceClient->getPage($pageId);

        $importedPageId = $page->getId();

        if ($importedPageId === null) {
            throw new \UnexpectedValueException(
                'The Confluence response did not contain a page ID.',
            );
        }

        $markdown = $this->confluenceContentConverter->toMarkdown(
            $page->getContent() ?? '',
        );

        return new ImportedPage(
            $importedPageId,
            $page->getTitle() ?? '',
            $markdown,
        );
    }
}
