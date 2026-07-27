<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluenceFolderMetadata;
use App\Confluence\ConfluenceFolderMetadataClientInterface;
use App\Confluence\ConfluenceRequestException;
use App\Confluence\ConfluenceSdkClientFactoryInterface;
use App\Tests\Support\QueueClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VirtualLast\ConfluenceV2\Configuration;
use VirtualLast\ConfluenceV2\ConfluenceClient as SdkClient;

final class ConfluenceClientTest extends TestCase
{
    public function testItMapsAViewPageAndResolvesItsSpace(): void
    {
        $transport = new QueueClient(
            $this->jsonResponse([
                'id' => '123',
                'title' => 'Architecture',
                'spaceId' => '42',
                'createdAt' => '2022-10-18T00:00:00.000Z',
                'body' => [
                    'view' => [
                        'representation' => 'view',
                        'value' => '<h1>Architecture</h1>',
                    ],
                ],
                'version' => ['number' => 7],
            ]),
            $this->jsonResponse(['id' => '42', 'key' => 'ENG']),
        );

        $page = $this->createClient($transport)->getPage('123');

        self::assertSame('123', $page->id);
        self::assertSame('Architecture', $page->title);
        self::assertSame('<h1>Architecture</h1>', $page->html);
        self::assertSame('42', $page->spaceId);
        self::assertSame('ENG', $page->spaceKey);
        self::assertSame(7, $page->version);
        $pageUri = $transport->requests[0]->getUri();
        self::assertSame('/wiki/api/v2/pages/123', $pageUri->getPath());
        parse_str($pageUri->getQuery(), $pageQuery);
        self::assertSame('view', $pageQuery['body-format'] ?? null);
        self::assertSame('/wiki/api/v2/spaces/42', $transport->requests[1]->getUri()->getPath());
    }

    public function testItCachesSpaceKeysForTheClientLifetime(): void
    {
        $transport = new QueueClient(
            $this->pageResponse('1', '42'),
            $this->jsonResponse(['id' => '42', 'key' => 'ENG']),
            $this->pageResponse('2', '42'),
        );
        $client = $this->createClient($transport);

        $client->getPage('1');
        $client->getPage('2');

        self::assertCount(3, $transport->requests);
        self::assertSame(1, count(array_filter(
            $transport->requests,
            static fn($request): bool => str_contains((string) $request->getUri(), '/spaces/42'),
        )));
    }

    public function testItRejectsAPageWithoutAViewBody(): void
    {
        $transport = new QueueClient($this->jsonResponse([
            'id' => '123',
            'title' => 'Broken',
            'spaceId' => '42',
            'body' => [],
            'version' => ['number' => 1],
        ]));

        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('did not contain a view body');

        $this->createClient($transport)->getPage('123');
    }

    public function testItRejectsAnIdOutsideTheSupportedIntegerRange(): void
    {
        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('positive integer within the supported range');

        $this->createClient(new QueueClient())->getPage('999999999999999999999999');
    }

    public function testItFetchesEveryPageOfChildReferencesInApiOrder(): void
    {
        $transport = new QueueClient(
            $this->jsonResponse([
                'results' => [
                    ['id' => '2', 'title' => 'Second', 'spaceId' => '42'],
                    ['id' => '3', 'title' => 'Third', 'spaceId' => '42'],
                ],
                '_links' => ['next' => '/wiki/api/v2/pages/1/children?cursor=next'],
            ]),
            $this->jsonResponse([
                'results' => [
                    ['id' => '4', 'title' => 'Fourth', 'spaceId' => '42'],
                ],
                '_links' => [],
            ]),
        );

        $children = $this->createClient($transport)->getChildPages('1');

        self::assertSame(['2', '3', '4'], array_column($children, 'id'));
        self::assertSame(['Second', 'Third', 'Fourth'], array_column($children, 'title'));
        self::assertStringContainsString('cursor=next', (string) $transport->requests[1]->getUri());
    }

    public function testItRejectsAnInvalidChildPageId(): void
    {
        $transport = new QueueClient($this->jsonResponse([
            'results' => [['id' => 'invalid', 'title' => 'Broken', 'spaceId' => '42']],
            '_links' => [],
        ]));

        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('invalid child page');

        $this->createClient($transport)->getChildPages('1');
    }

    public function testItFetchesFolderMetadataAndEveryPageOfSupportedDirectChildren(): void
    {
        $transport = new QueueClient(
            $this->jsonResponse([
                'results' => [
                    [
                        'id' => '11',
                        'status' => 'current',
                        'title' => 'Authentication',
                        'type' => 'folder',
                        'spaceId' => '42',
                    ],
                    [
                        'id' => '12',
                        'status' => 'current',
                        'title' => 'Overview',
                        'type' => 'page',
                        'spaceId' => '42',
                    ],
                    [
                        'id' => '13',
                        'status' => 'current',
                        'title' => 'Diagram',
                        'type' => 'whiteboard',
                        'spaceId' => '42',
                    ],
                ],
                '_links' => ['next' => '/wiki/api/v2/folders/10/direct-children?cursor=next'],
            ]),
            $this->jsonResponse([
                'results' => [[
                    'id' => '14',
                    'status' => 'current',
                    'title' => 'SSO',
                    'type' => 'folder',
                    'spaceId' => '42',
                ]],
                '_links' => [],
            ]),
        );

        $folder = $this->createClient($transport, 'Engineering')->getFolder('10');

        self::assertSame('10', $folder->id);
        self::assertSame('Engineering', $folder->title);
        self::assertSame(['11', '12', '14'], array_column($folder->items, 'id'));
        self::assertSame(['folder', 'page', 'folder'], array_map(
            static fn($item): string => $item->type->value,
            $folder->items,
        ));
        self::assertSame('/wiki/api/v2/folders/10/direct-children', $transport->requests[0]->getUri()->getPath());
        self::assertStringContainsString('cursor=next', (string) $transport->requests[1]->getUri());
    }

    public function testItRejectsAnInvalidFolderItemId(): void
    {
        $transport = new QueueClient(
            $this->jsonResponse([
                'results' => [[
                    'id' => 'invalid',
                    'status' => 'current',
                    'title' => 'Broken',
                    'type' => 'page',
                    'spaceId' => '42',
                ]],
                '_links' => [],
            ]),
        );

        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('invalid direct child ID');

        $this->createClient($transport, 'Engineering')->getFolder('10');
    }

    public function testItFetchesMixedPageDirectChildrenInApiOrder(): void
    {
        $transport = new QueueClient($this->jsonResponse([
            'results' => [
                [
                    'id' => '11',
                    'status' => 'current',
                    'title' => 'Child page',
                    'type' => 'page',
                    'spaceId' => '42',
                ],
                [
                    'id' => '12',
                    'status' => 'current',
                    'title' => 'Child folder',
                    'type' => 'folder',
                    'spaceId' => '42',
                ],
                [
                    'id' => '13',
                    'status' => 'current',
                    'title' => 'Unsupported database',
                    'type' => 'database',
                    'spaceId' => '42',
                ],
            ],
            '_links' => [],
        ]));

        $items = $this->createClient($transport)->getPageDirectChildren('10');

        self::assertSame(['11', '12'], array_column($items, 'id'));
        self::assertSame(['page', 'folder'], array_map(
            static fn($item): string => $item->type->value,
            $items,
        ));
        self::assertSame(
            '/wiki/api/v2/pages/10/direct-children',
            $transport->requests[0]->getUri()->getPath(),
        );
    }

    public function testItPaginatesAndDownloadsAttachmentsIntoLocalModels(): void
    {
        $transport = new QueueClient(
            $this->attachmentPage('att1', 'first.txt', '/wiki/download/first.txt', 'next'),
            $this->attachmentMetadata('att1', 'first.txt', '/wiki/download/first.txt', 5),
            new Response(200, [], 'first'),
            $this->attachmentPage('att2', 'second.bin', '/wiki/download/second.bin'),
            $this->attachmentMetadata('att2', 'second.bin', '/wiki/download/second.bin', 6),
            new Response(200, [], 'second'),
        );

        $attachments = $this->createClient($transport)->getPageAttachments('1');

        self::assertCount(2, $attachments);
        self::assertSame(['att1', 'att2'], array_column($attachments, 'id'));
        self::assertSame(['first', 'second'], array_column($attachments, 'content'));
        self::assertSame(['/wiki/download/first.txt', '/wiki/download/second.bin'], array_column($attachments, 'downloadUrl'));
        self::assertSame([5, 6], array_column($attachments, 'size'));
        self::assertStringContainsString('cursor=next', (string) $transport->requests[3]->getUri());
    }

    public function testItTranslatesApiFailuresToTheLocalException(): void
    {
        $transport = new QueueClient($this->jsonResponse(['message' => 'Missing page'], 404));

        try {
            $this->createClient($transport)->getPage('404');
            self::fail('Expected a local Confluence exception.');
        } catch (ConfluenceRequestException $exception) {
            self::assertSame(404, $exception->getCode());
            self::assertSame('Confluence returned HTTP 404: Missing page', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testItTranslatesTransportFailuresToTheLocalException(): void
    {
        $transport = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('Network unavailable') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->createClient($transport)->getPage('123');
            self::fail('Expected a local Confluence exception.');
        } catch (ConfluenceRequestException $exception) {
            self::assertSame('The Confluence request could not be completed.', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    private function createClient(
        ClientInterface $transport,
        string $folderTitle = 'Folder',
    ): ConfluenceClient {
        $factory = new Psr17Factory();
        $sdkClient = SdkClient::create(
            new Configuration('https://example.atlassian.net', 'user@example.com', 'token'),
            $transport,
            $factory,
            $factory,
        );

        $sdkFactory = new class ($sdkClient) implements ConfluenceSdkClientFactoryInterface {
            public function __construct(private readonly SdkClient $client) {}

            public function create(): SdkClient
            {
                return $this->client;
            }
        };
        $metadataClient = new class ($folderTitle) implements ConfluenceFolderMetadataClientInterface {
            public function __construct(private readonly string $folderTitle) {}

            public function getFolder(string $folderId): ConfluenceFolderMetadata
            {
                return new ConfluenceFolderMetadata($folderId, $this->folderTitle);
            }
        };

        return new ConfluenceClient($sdkFactory, $metadataClient);
    }

    private function pageResponse(string $id, string $spaceId): Response
    {
        return $this->jsonResponse([
            'id' => $id,
            'title' => 'Page ' . $id,
            'spaceId' => $spaceId,
            'body' => [
                'view' => [
                    'representation' => 'view',
                    'value' => '<p>Page</p>',
                ],
            ],
            'version' => ['number' => 1],
        ]);
    }

    private function attachmentPage(
        string $id,
        string $title,
        string $downloadLink,
        ?string $cursor = null,
    ): Response {
        return $this->jsonResponse([
            'results' => [[
                'id' => $id,
                'title' => $title,
                'downloadLink' => $downloadLink,
            ]],
            '_links' => $cursor === null
                ? []
                : ['next' => '/wiki/api/v2/pages/1/attachments?cursor=' . $cursor],
        ]);
    }

    private function attachmentMetadata(
        string $id,
        string $title,
        string $downloadLink,
        int $size,
    ): Response {
        return $this->jsonResponse([
            'id' => $id,
            'title' => $title,
            'downloadLink' => $downloadLink,
            'mediaType' => 'application/octet-stream',
            'fileSize' => $size,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
