<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ConfluenceId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ConfluenceFolderMetadataClient implements ConfluenceFolderMetadataClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private string $confluenceBaseUrl,
        #[Autowire(env: 'CONFLUENCE_EMAIL')]
        private string $confluenceEmail,
        #[Autowire(env: 'CONFLUENCE_API_TOKEN')]
        private string $privateAccessToken,
    ) {}

    public function getFolder(string $folderId): ConfluenceFolderMetadata
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf(
                    '%s/wiki/api/v2/folders/%d',
                    rtrim($this->confluenceBaseUrl, '/'),
                    ConfluenceId::toInt($folderId),
                ),
                [
                    'auth_basic' => [
                        $this->confluenceEmail,
                        $this->privateAccessToken,
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ],
            );
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                $message = is_string($data['message'] ?? null)
                    ? ': ' . $data['message']
                    : '.';

                throw new ConfluenceRequestException(sprintf(
                    'Confluence returned HTTP %d%s',
                    $statusCode,
                    $message,
                ), $statusCode);
            }

            $id = $data['id'] ?? null;
            $title = $data['title'] ?? null;

            if (!is_string($id) || !is_string($title)) {
                throw new ConfluenceRequestException(
                    'The Confluence folder response did not contain a valid ID and title.',
                );
            }

            if ($id !== $folderId) {
                throw new ConfluenceRequestException(sprintf(
                    'Confluence folder %s was not found.',
                    $folderId,
                ));
            }

            return new ConfluenceFolderMetadata($id, $title);
        } catch (ConfluenceRequestException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new ConfluenceRequestException(
                'The Confluence request could not be completed.',
                previous: $exception,
            );
        } catch (\Throwable $exception) {
            throw new ConfluenceRequestException(
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'The Confluence response could not be processed.',
                previous: $exception,
            );
        }
    }
}
