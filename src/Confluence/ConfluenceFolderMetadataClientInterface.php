<?php

declare(strict_types=1);

namespace App\Confluence;

interface ConfluenceFolderMetadataClientInterface
{
    /**
     * @throws ConfluenceRequestException
     */
    public function getFolder(string $folderId): ConfluenceFolderMetadata;
}
