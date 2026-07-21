<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluencePageReference;
use CloudPlayDev\ConfluenceClient\Api\Content;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentAttachment;
use CloudPlayDev\ConfluenceClient\Entity\ContentBody;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use CloudPlayDev\ConfluenceClient\Exception\HttpClientException;
use CloudPlayDev\ConfluenceClient\Exception\HttpServerException;
use Http\Client\Exception;
use Psr\Http\Message\ResponseInterface;

readonly class ConfluenceClient
{
    public function __construct(
        private ConfluenceSdkClientFactoryInterface $clientFactory,
    ) {}

    /**
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function getPage(string $pageId): AbstractContent
    {
        try {
            $contentApi = $this->clientFactory->create()->content();
            $page = $contentApi
                ->find(['id' => $pageId], 1)
                ->getResultAt(0);

            if ($page === null || (string) $page->getId() !== $pageId) {
                throw new ConfluencePhpClientException(sprintf(
                    'Confluence page %s was not found.',
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
     * @return list<ConfluencePageReference>
     *
     * @throws ConfluencePhpClientException
     */
    public function getChildPages(string $pageId): array
    {
        $limit = 100;
        $start = 0;
        $children = [];

        try {
            $httpClient = $this->clientFactory->create()->getHttpClient();

            do {
                $response = $httpClient->get(sprintf(
                    '/rest/api/content/%s/child/page?%s',
                    rawurlencode($pageId),
                    http_build_query(['start' => $start, 'limit' => $limit]),
                ));
                $this->assertSuccessfulResponse($response);
                $data = json_decode(
                    $response->getBody()->getContents(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
                    throw new \UnexpectedValueException('The Confluence child-page response was malformed.');
                }

                $resultCount = count($data['results']);

                foreach ($data['results'] as $result) {
                    if (!is_array($result) || ($result['type'] ?? null) !== Content::CONTENT_TYPE_PAGE) {
                        throw new \UnexpectedValueException('The Confluence response contained an invalid child page.');
                    }

                    $childId = isset($result['id']) && (is_string($result['id']) || is_int($result['id']))
                        ? (string) $result['id']
                        : '';

                    if (preg_match('/^[1-9]\d*$/', $childId) !== 1) {
                        throw new \UnexpectedValueException('The Confluence response contained an invalid child page ID.');
                    }

                    $title = $result['title'] ?? null;

                    if ($title !== null && !is_string($title)) {
                        throw new \UnexpectedValueException('The Confluence response contained an invalid child page title.');
                    }

                    $children[] = new ConfluencePageReference($childId, $title);
                }

                $hasNextPage = isset($data['_links']['next']);

                if ($hasNextPage && $resultCount === 0) {
                    throw new \UnexpectedValueException('Confluence returned an empty child-page result with a next page.');
                }

                $start += $resultCount;
            } while ($hasNextPage);

            return $children;
        } catch (ConfluencePhpClientException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConfluencePhpClientException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
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

            $this->assertSuccessfulResponse($response);

            return $response->getBody()->getContents();
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function assertSuccessfulResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if (in_array($statusCode, [200, 201, 202, 204], true)) {
            return;
        }

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

}
