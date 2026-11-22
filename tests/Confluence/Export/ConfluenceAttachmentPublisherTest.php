<?php

declare(strict_types=1);

namespace App\Tests\Confluence\Export;

use App\Confluence\ConfluenceRequestException;
use App\Confluence\Export\ConfluenceAttachmentPublisher;
use App\Confluence\Export\ExportImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConfluenceAttachmentPublisherTest extends TestCase
{
    public function testItSendsMultipartUpsertsWithStableNamesAndUpdatedBytes(): void
    {
        $responses = [
            new MockResponse('{"results":[{"id":"att123","title":"obsidian-image.png"}]}'),
            new MockResponse('{"results":[{"id":"att123","title":"obsidian-image.png"}]}'),
        ];
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($responses, &$bodies): MockResponse {
            $reader = $options['body'];
            self::assertInstanceOf(\Closure::class, $reader);
            $body = '';
            while (($chunk = $reader(16384)) !== '') {
                $body .= $chunk;
            }
            $response = $responses[count($bodies)];
            $bodies[] = $body;
            return $response;
        });
        $publisher = $this->publisher($http);
        foreach (['first image bytes', 'changed image bytes'] as $bytes) {
            $publisher->publish('123', new ExportImage('image.png', 'obsidian-image.png', 'image/png', $bytes));
        }
        foreach ($responses as $index => $response) {
            self::assertSame('PUT', $response->getRequestMethod());
            self::assertSame('https://example.atlassian.net/wiki/rest/api/content/123/child/attachment', $response->getRequestUrl());
            $options = $response->getRequestOptions();
            self::assertSame(['X-Atlassian-Token: nocheck'], $options['normalized_headers']['x-atlassian-token']);
            self::assertSame(0, $options['max_redirects']);
            $body = $bodies[$index];
            self::assertStringContainsString('name="file"; filename="obsidian-image.png"', $body);
            self::assertStringContainsString('Content-Type: image/png', $body);
            self::assertStringContainsString($index === 0 ? 'first image bytes' : 'changed image bytes', $body);
        }
        self::assertSame(2, $http->getRequestsCount());
    }

    #[DataProvider('failures')]
    public function testItRejectsFailuresWithoutRetrying(string $body, int $status): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        try {
            $this->publisher($http)->publish('123', new ExportImage('image.png', 'obsidian-image.png', 'image/png', 'bytes'));
            self::fail('Expected upload failure.');
        } catch (ConfluenceRequestException) {
            self::assertSame(1, $http->getRequestsCount());
        }
    }

    /** @return iterable<array{string, int}> */
    public static function failures(): iterable
    {
        yield ['{}', 403];
        yield ['{}', 500];
        yield ['{"results":[]}', 200];
        yield ['{"results":[{"id":"att123","title":"wrong.png"}]}', 200];
        yield ['not json', 200];
    }

    private function publisher(MockHttpClient $http): ConfluenceAttachmentPublisher
    {
        return new ConfluenceAttachmentPublisher($http, 'https://example.atlassian.net', 'user@example.com', 'token');
    }
}
