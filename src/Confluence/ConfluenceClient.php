<?php

declare(strict_types=1);

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\Api\Content;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentBody;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;

readonly class ConfluenceClient
{
    public function __construct(
        private ConfluenceSdkClientFactoryInterface $clientFactory,
    ) {}

    /**
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function getPage(int $pageId): AbstractContent
    {
        try {
            $contentApi = $this->clientFactory->create()->content();
            $page = $contentApi->get($pageId);

            if ($page === null) {
                throw new ConfluencePhpClientException(sprintf(
                    'Confluence page %d was not found.',
                    $pageId,
                ));
            }

            $storageBody = new ContentBody();
            $storageBody
                ->setRepresentation(ContentBody::REPRESENTATION_STORAGE)
                ->setValue($page->getContent() ?? '')
            ;

            $renderedBody = $contentApi->convert(
                $storageBody,
                ContentBody::REPRESENTATION_EXPORT_VIEW,
                $page,
            );

            $page->setContent($renderedBody->getValue());

            return $page;
        } catch (Exception $e) {
            throw new ConfluencePhpClientException(
                $e->getMessage(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * @throws Exception
     * @throws \JsonException
     * @throws ConfluencePhpClientException
     */
    public function getPageChildren(AbstractContent $page): ContentSearchResult
    {
        try {
            return $this->clientFactory
                ->create()
                ->content()
                ->children($page, Content::CONTENT_TYPE_PAGE);
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

}
