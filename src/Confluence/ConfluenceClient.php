<?php

declare(strict_types=1);

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\Api\Content;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentAttachment;
use CloudPlayDev\ConfluenceClient\Entity\ContentBody;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use CloudPlayDev\ConfluenceClient\Exception\HttpClientException;
use CloudPlayDev\ConfluenceClient\Exception\HttpServerException;
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

    /**
     * @throws Exception
     * @throws \JsonException
     * @throws ConfluencePhpClientException
     */
    public function getPageAttachments(AbstractContent $page): ContentSearchResult
    {
        try {
            return $this->clientFactory
                ->create()
                ->content()
                ->children($page, Content::CONTENT_TYPE_ATTACHMENT);
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     */
    public function downloadAttachment(ContentAttachment $attachment): string
    {
        $downloadUrl = $attachment->getDownloadUrl();

        if ($downloadUrl === null) {
            throw new ConfluencePhpClientException('The Confluence attachment does not contain a download URL.');
        }

        try {
            $response = $this->clientFactory
                ->create()
                ->getHttpClient()
                ->get($downloadUrl);

            $statusCode = $response->getStatusCode();

            if (!in_array($statusCode, [200, 201, 202, 204], true)) {
                if ($statusCode >= 500) {
                    throw HttpServerException::serverError($response);
                }

                throw match ($statusCode) {
                    400 => HttpClientException::badRequest($response),
                    401 => HttpClientException::unauthorized($response),
                    402 => HttpClientException::requestFailed($response),
                    403 => HttpClientException::forbidden($response),
                    404 => HttpClientException::notFound($response),
                    409 => HttpClientException::conflict($response),
                    413 => HttpClientException::payloadTooLarge($response),
                    429 => HttpClientException::tooManyRequests($response),
                    default => new ConfluencePhpClientException(
                        $response->getBody()->getContents(),
                        $statusCode,
                    ),
                };
            }

            return $response->getBody()->getContents();
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

}
