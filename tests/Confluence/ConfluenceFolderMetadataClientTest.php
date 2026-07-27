<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceFolderMetadataClient;
use App\Confluence\ConfluenceRequestException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConfluenceFolderMetadataClientTest extends TestCase
{
    public function testItReadsOnlyRequiredFolderMetadataFromTheRawResponse(): void
    {
        $response = new MockResponse(json_encode([
            'id' => '823918593',
            'title' => 'Gen2 Portal',
            'createdAt' => '1781255911132',
        ], JSON_THROW_ON_ERROR));
        $httpClient = new MockHttpClient($response);
        $client = new ConfluenceFolderMetadataClient(
            $httpClient,
            'https://example.atlassian.net',
            'user@example.com',
            'token',
        );

        $folder = $client->getFolder('823918593');

        self::assertSame('823918593', $folder->id);
        self::assertSame('Gen2 Portal', $folder->title);
        self::assertSame(
            'https://example.atlassian.net/wiki/api/v2/folders/823918593',
            $response->getRequestUrl(),
        );
        self::assertSame(
            'Authorization: Basic ' . base64_encode('user@example.com:token'),
            $response->getRequestOptions()['normalized_headers']['authorization'][0],
        );
    }

    public function testItTranslatesApiErrors(): void
    {
        $client = new ConfluenceFolderMetadataClient(
            new MockHttpClient(new MockResponse(
                '{"message":"Missing folder"}',
                ['http_code' => 404],
            )),
            'https://example.atlassian.net',
            'user@example.com',
            'token',
        );

        try {
            $client->getFolder('823918593');
            self::fail('Expected a local Confluence exception.');
        } catch (ConfluenceRequestException $exception) {
            self::assertSame(404, $exception->getCode());
            self::assertSame(
                'Confluence returned HTTP 404: Missing folder',
                $exception->getMessage(),
            );
        }
    }
}
