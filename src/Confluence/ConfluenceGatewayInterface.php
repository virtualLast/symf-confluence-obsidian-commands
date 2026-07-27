<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluenceAttachment;
use App\Model\ConfluenceFolder;
use App\Model\ConfluenceFolderItem;
use App\Model\ConfluencePage;
use App\Model\ConfluencePageReference;

interface ConfluenceGatewayInterface
{
    /**
     * @throws ConfluenceRequestException
     */
    public function getFolder(string $folderId): ConfluenceFolder;

    /**
     * @return list<ConfluenceFolderItem>
     *
     * @throws ConfluenceRequestException
     */
    public function getPageDirectChildren(string $pageId): array;

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
