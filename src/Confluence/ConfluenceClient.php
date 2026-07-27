<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluenceAttachment;
use App\Model\ConfluenceFolder;
use App\Model\ConfluenceFolderItem;
use App\Model\ConfluenceFolderItemType;
use App\Model\ConfluenceId;
use App\Model\ConfluencePage;
use App\Model\ConfluencePageReference;
use VirtualLast\ConfluenceV2\Exception\ApiException;
use VirtualLast\ConfluenceV2\Exception\TransportException;
use VirtualLast\ConfluenceV2\Generated\Model\AttachmentBulk;
use VirtualLast\ConfluenceV2\Generated\Model\ChildPage;
use VirtualLast\ConfluenceV2\Generated\Model\ChildrenResponse;

final class ConfluenceClient implements ConfluenceGatewayInterface
{
    /** @var array<string, string> */
    private array $spaceKeys = [];

    public function __construct(
        private readonly ConfluenceSdkClientFactoryInterface $clientFactory,
        private readonly ConfluenceFolderMetadataClientInterface $folderMetadataClient,
    ) {}

    public function getPage(string $pageId): ConfluencePage
    {
        try {
            $page = $this->clientFactory
                ->create()
                ->pages()
                ->getPageById(ConfluenceId::toInt($pageId), ['body-format' => 'view']);

            if ($page->getId() !== $pageId) {
                throw new \UnexpectedValueException(sprintf(
                    'Confluence page %s was not found.',
                    $pageId,
                ));
            }

            if (!$page->isInitialized('body') || !$page->getBody()->isInitialized('view')) {
                throw new \UnexpectedValueException('The Confluence page response did not contain a view body.');
            }

            $spaceId = $page->getSpaceId();

            return new ConfluencePage(
                id: $page->getId(),
                title: $page->getTitle(),
                html: $page->getBody()->getView()->getValue(),
                spaceId: $spaceId,
                spaceKey: $this->spaceKey($spaceId),
                version: $page->getVersion()->getNumber(),
            );
        } catch (\Throwable $exception) {
            throw $this->requestException($exception);
        }
    }

    public function getFolder(string $folderId): ConfluenceFolder
    {
        try {
            $client = $this->clientFactory->create();
            $folder = $this->folderMetadataClient->getFolder($folderId);

            $items = $this->mapDirectChildren(
                $client->children()->iterateFolderDirectChildren(
                    ConfluenceId::toInt($folderId),
                ),
            );

            return new ConfluenceFolder(
                $folder->id,
                $folder->title,
                $items,
            );
        } catch (\Throwable $exception) {
            throw $this->requestException($exception);
        }
    }

    public function getPageDirectChildren(string $pageId): array
    {
        try {
            return $this->mapDirectChildren(
                $this->clientFactory
                    ->create()
                    ->children()
                    ->iteratePageDirectChildren(ConfluenceId::toInt($pageId)),
            );
        } catch (\Throwable $exception) {
            throw $this->requestException($exception);
        }
    }

    public function getChildPages(string $pageId): array
    {
        try {
            $children = [];
            $results = $this->clientFactory
                ->create()
                ->children()
                ->iterateChildPages(ConfluenceId::toInt($pageId));

            foreach ($results as $result) {
                if (!$result instanceof ChildPage || !ConfluenceId::isValid($result->getId())) {
                    throw new \UnexpectedValueException('The Confluence response contained an invalid child page.');
                }

                $children[] = new ConfluencePageReference(
                    $result->getId(),
                    $result->getTitle(),
                );
            }

            return $children;
        } catch (\Throwable $exception) {
            throw $this->requestException($exception);
        }
    }

    public function getPageAttachments(string $pageId): array
    {
        try {
            $client = $this->clientFactory->create();
            $attachments = [];

            foreach ($client->attachments()->iteratePageAttachments(
                ConfluenceId::toInt($pageId),
                ['status' => ['current']],
            ) as $metadata) {
                if (!$metadata instanceof AttachmentBulk) {
                    throw new \UnexpectedValueException('The Confluence response contained an invalid attachment.');
                }

                $download = $client->attachments()->downloadById($metadata->getId());

                $attachments[] = new ConfluenceAttachment(
                    id: $metadata->getId(),
                    filename: $download->filename,
                    content: (string) $download->stream,
                    mediaType: $download->mediaType,
                    size: $download->size,
                    downloadUrl: $metadata->isInitialized('downloadLink')
                        ? $metadata->getDownloadLink()
                        : null,
                );
            }

            return $attachments;
        } catch (\Throwable $exception) {
            throw $this->requestException($exception);
        }
    }

    private function spaceKey(string $spaceId): string
    {
        if (isset($this->spaceKeys[$spaceId])) {
            return $this->spaceKeys[$spaceId];
        }

        $space = $this->clientFactory
            ->create()
            ->spaces()
            ->getSpaceById(ConfluenceId::toInt($spaceId));

        if ($space->getId() !== $spaceId) {
            throw new \UnexpectedValueException(sprintf(
                'Confluence space %s was not found.',
                $spaceId,
            ));
        }

        return $this->spaceKeys[$spaceId] = $space->getKey();
    }

    /**
     * @param iterable<mixed> $children
     *
     * @return list<ConfluenceFolderItem>
     */
    private function mapDirectChildren(iterable $children): array
    {
        $items = [];

        foreach ($children as $child) {
            if (!$child instanceof ChildrenResponse) {
                throw new \UnexpectedValueException('The Confluence response contained an invalid direct child.');
            }

            $type = ConfluenceFolderItemType::tryFrom($child->getType());

            if ($type === null) {
                continue;
            }

            if (!ConfluenceId::isValid($child->getId())) {
                throw new \UnexpectedValueException('The Confluence response contained an invalid direct child ID.');
            }

            $items[] = new ConfluenceFolderItem(
                $child->getId(),
                $child->getTitle(),
                $type,
            );
        }

        return $items;
    }

    private function requestException(\Throwable $exception): ConfluenceRequestException
    {
        if ($exception instanceof ConfluenceRequestException) {
            return $exception;
        }

        $code = $exception instanceof ApiException ? $exception->statusCode : 0;
        $message = match (true) {
            $exception instanceof TransportException => 'The Confluence request could not be completed.',
            $exception->getMessage() !== '' => $exception->getMessage(),
            default => 'The Confluence response could not be processed.',
        };

        return new ConfluenceRequestException($message, $code, $exception);
    }
}
