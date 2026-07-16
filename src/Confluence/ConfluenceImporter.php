<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use CloudPlayDev\ConfluenceClient\Entity\ContentAttachment;
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
    public function importPage(int $pageId, bool $withAttachments = false): ImportedPage
    {
        $page = $this->confluenceClient->getPage($pageId);

        $importedPageId = filter_var(
            $page->getId(),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($importedPageId === false) {
            throw new \UnexpectedValueException(
                'The Confluence response did not contain a valid page ID.',
            );
        }

        $attachments = [];

        if ($withAttachments) {
            $confluenceAttachments = $this->confluenceClient
                ->getPageAttachments($page)
                ->getResults();

            foreach ($confluenceAttachments as $attachment) {
                if (!$attachment instanceof ContentAttachment) {
                    throw new \UnexpectedValueException(
                        'The Confluence response contained an invalid attachment.',
                    );
                }

                $attachments[] = new ImportedAttachment(
                    id: (string) $attachment->getId(),
                    filename: (string) $attachment->getTitle(),
                    contents: $this->confluenceClient->downloadAttachment($attachment),
                );
            }
        }

        $markdown = $this->confluenceContentConverter->toMarkdown(
            $page->getContent() ?? '',
        );

        return new ImportedPage(
            $importedPageId,
            $page->getTitle() ?? '',
            $markdown,
            $attachments,
        );
    }
}
