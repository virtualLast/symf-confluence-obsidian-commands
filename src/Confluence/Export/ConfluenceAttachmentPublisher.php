<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use App\Confluence\ConfluenceRequestException;
use App\Model\ConfluenceId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VirtualLast\ConfluenceV2\Configuration;

final readonly class ConfluenceAttachmentPublisher implements ConfluenceAttachmentPublisherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private string $baseUrl,
        #[Autowire(env: 'CONFLUENCE_EMAIL')]
        private string $email,
        #[Autowire(env: 'CONFLUENCE_API_TOKEN')]
        private string $apiToken,
    ) {}

    /** Use the v1 multipart upsert endpoint, which is absent from the installed v2 SDK. */
    public function publish(string $pageId, ExportImage $image): void
    {
        try {
            $configuration = new Configuration($this->baseUrl, $this->email, $this->apiToken);
            $form = new FormDataPart([
                'file' => new DataPart($image->content, $image->filename, $image->mediaType),
                'minorEdit' => 'true',
            ]);
            $response = $this->httpClient->request('PUT', sprintf(
                '%s/wiki/rest/api/content/%d/child/attachment',
                $configuration->baseUrl,
                ConfluenceId::toInt($pageId),
            ), [
                'auth_basic' => [$this->email, $this->apiToken],
                'max_redirects' => 0,
                'headers' => array_merge($form->getPreparedHeaders()->toArray(), [
                    'Accept: application/json',
                    'X-Atlassian-Token: nocheck',
                ]),
                'body' => $form->bodyToIterable(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ConfluenceRequestException(sprintf('Attachment upload failed with HTTP %d: %s', $status, $image->vaultPath), $status);
            }
            $data = $response->toArray(false);
            $attachment = $data['results'][0] ?? null;
            if (!is_array($attachment) || !is_string($attachment['id'] ?? null) || $attachment['id'] === '' || ($attachment['title'] ?? null) !== $image->filename) {
                throw new ConfluenceRequestException('Attachment upload response could not be verified: ' . $image->vaultPath);
            }
        } catch (ConfluenceRequestException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConfluenceRequestException('Attachment upload could not be completed or verified: ' . $image->vaultPath, previous: $exception);
        }
    }
}
