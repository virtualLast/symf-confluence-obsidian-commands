<?php

declare(strict_types=1);

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\ConfluenceClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfluenceSdkClientFactory implements ConfluenceSdkClientFactoryInterface
{
    public function __construct(
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private string $confluenceBaseUrl,
        #[Autowire(env: 'CONFLUENCE_EMAIL')]
        private string $confluenceEmail,
        #[Autowire(env: 'CONFLUENCE_API_TOKEN')]
        private string $privateAccessToken,
    ) {}

    public function create(): ConfluenceClient
    {
        return new ConfluenceClient($this->confluenceBaseUrl)
            ->authenticateBasicAuth(
                $this->confluenceEmail,
                $this->privateAccessToken,
            );
    }
}
