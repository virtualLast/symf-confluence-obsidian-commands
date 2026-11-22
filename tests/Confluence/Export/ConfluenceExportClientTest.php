<?php

declare(strict_types=1);

namespace App\Tests\Confluence\Export;

use App\Confluence\ConfluenceRequestException;
use App\Confluence\ConfluenceSdkClientFactoryInterface;
use App\Confluence\Export\ConfluenceExportClient;
use App\Confluence\Export\ExportPage;
use App\Tests\Support\QueueClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VirtualLast\ConfluenceV2\Configuration;
use VirtualLast\ConfluenceV2\ConfluenceClient;

final class ConfluenceExportClientTest extends TestCase
{
    public function testItReadsStorageAndUpdatesOnlyBodyWithNextVersionAndExistingTitle(): void
    {
        $transport = new QueueClient(
            $this->response(['id' => '123', 'status' => 'current', 'title' => 'Remote title', 'version' => ['number' => 9], 'body' => ['storage' => ['value' => '<p>Remote edit</p>']]]),
            $this->response(['id' => '123', 'version' => ['number' => 10]]),
        );
        $client = $this->client($transport);
        $page = $client->getPage('123');
        self::assertSame('<p>Remote edit</p>', $page->storage);
        self::assertSame(9, $page->version);
        self::assertSame(10, $client->publish($page, '<h1>Local</h1>'));
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('body-format=storage', (string) $transport->requests[0]->getUri());
        $request = $transport->requests[1];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/wiki/api/v2/pages/123', $request->getUri()->getPath());
        self::assertSame([
            'id' => '123',
            'status' => 'current',
            'title' => 'Remote title',
            'body' => ['representation' => 'storage', 'value' => '<h1>Local</h1>'],
            'version' => ['number' => 10],
        ], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('failures')]
    public function testItDoesNotRetryFailedWrites(int $status): void
    {
        $transport = new QueueClient($this->response(['message' => 'Write failed'], $status));
        try {
            $this->client($transport)->publish(new ExportPage('123', 'Title', '', 9), '<p>Local</p>');
            self::fail('Expected a request exception.');
        } catch (ConfluenceRequestException $exception) {
            self::assertSame($status, $exception->getCode());
            if ($status === 409) {
                self::assertStringContainsString('changed during publication', $exception->getMessage());
            }
        }
        self::assertCount(1, $transport->requests);
    }

    /** @return iterable<array{int}> */
    public static function failures(): iterable
    {
        yield [409];
        yield [403];
        yield [500];
    }

    public function testItRejectsDraftDestination(): void
    {
        $transport = new QueueClient($this->response(['id' => '123', 'status' => 'draft']));
        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('published Confluence page');
        $this->client($transport)->getPage('123');
    }

    public function testItDoesNotReportAnUnverifiedWriteAsSuccess(): void
    {
        $transport = new QueueClient($this->response(['id' => '456', 'version' => ['number' => 10]]));
        $this->expectException(ConfluenceRequestException::class);
        $this->expectExceptionMessage('could not be verified');
        $this->client($transport)->publish(new ExportPage('123', 'Title', '', 9), '<p>Local</p>');
    }

    private function client(QueueClient $transport): ConfluenceExportClient
    {
        $psr = new Psr17Factory();
        $sdk = ConfluenceClient::create(new Configuration('https://example.atlassian.net', 'user@example.com', 'token'), $transport, $psr, $psr);
        $factory = new class ($sdk) implements ConfluenceSdkClientFactoryInterface {
            public function __construct(private readonly ConfluenceClient $sdk) {}

            public function create(): ConfluenceClient
            {
                return $this->sdk;
            }
        };

        return new ConfluenceExportClient($factory);
    }

    /** @param array<string, mixed> $body */
    private function response(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
