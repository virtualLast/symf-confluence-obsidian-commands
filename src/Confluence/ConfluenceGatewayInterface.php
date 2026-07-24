<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluenceAttachment;
use App\Model\ConfluencePage;
use App\Model\ConfluencePageReference;

interface ConfluenceGatewayInterface
{
    /**
     * @throws ConfluenceRequestException
     */
    public function getPage(string $pageId): ConfluencePage;

    /**
     * @return list<ConfluencePageReference>
     *
     * @throws ConfluenceRequestException
     */
    public function getChildPages(string $pageId): array;

    /**
     * @return list<ConfluenceAttachment>
     *
     * @throws ConfluenceRequestException
     */
    public function getPageAttachments(string $pageId): array;
}
