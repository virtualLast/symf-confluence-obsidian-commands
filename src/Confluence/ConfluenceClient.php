<?php

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\Api\Content;
use CloudPlayDev\ConfluenceClient\Entity\AbstractContent;
use CloudPlayDev\ConfluenceClient\Entity\ContentPage;
use CloudPlayDev\ConfluenceClient\ConfluenceClient as Client;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ConfluenceClient
{
    public function __construct(
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private string $confluenceBaseUrl,
        #[Autowire(env: 'CONFLUENCE_EMAIL')]
        private string $confluenceEmail,
        #[Autowire(env: 'CONFLUENCE_API_TOKEN')]
        private string $privateAccessToken,
    ) {
    }

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function getPage(int $pageId): AbstractContent
    {
        try {
            return $this->getClient()->content()->get($pageId);
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
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
            return $this->getClient()->content()->children($page, Content::CONTENT_TYPE_PAGE);
        } catch (Exception $e) {
            throw new ConfluencePhpClientException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function getClient(): Client
    {
        return new Client($this->confluenceBaseUrl)
            ->authenticateBasicAuth($this->confluenceEmail, $this->privateAccessToken)
        ;
    }
}

