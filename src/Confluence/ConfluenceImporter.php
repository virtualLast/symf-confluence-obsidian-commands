<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluenceAttachment;
use App\Model\ImportedAttachment;
use App\Model\ImportedPage;

readonly class ConfluenceImporter
{
    public function __construct(
        private ConfluenceGatewayInterface $confluenceGateway,
        private ConfluenceContentConverter $confluenceContentConverter,
        private ConfluenceLinkedPageExtractor $linkedPageExtractor,
    ) {}

    public function importPage(string $pageId, bool $withAttachments = false): ImportedPage
    {
        $page = $this->confluenceGateway->getPage($pageId);
        $attachments = array_map(
            static fn(ConfluenceAttachment $attachment): ImportedAttachment => new ImportedAttachment(
                id: $attachment->id,
                filename: $attachment->filename,
                content: $attachment->content,
                downloadUrl: $attachment->downloadUrl,
            ),
            $withAttachments ? $this->confluenceGateway->getPageAttachments($page->id) : [],
        );

        $markdown = $this->confluenceContentConverter->toMarkdown(
            $page->html,
            $attachments,
        );

        return new ImportedPage(
            id: $page->id,
            title: $page->title,
            content: $markdown,
            attachments: $attachments,
            spaceId: $page->spaceId,
            spaceKey: $page->spaceKey,
            version: $page->version,
            linkedPages: $this->linkedPageExtractor->extract($page->html),
        );
    }
}
