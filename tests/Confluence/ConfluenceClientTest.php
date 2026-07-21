<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluenceSdkClientFactoryInterface;
use CloudPlayDev\ConfluenceClient\Api\Content;
use CloudPlayDev\ConfluenceClient\ConfluenceClient as SdkClient;
use CloudPlayDev\ConfluenceClient\Entity\ContentAttachment;
use CloudPlayDev\ConfluenceClient\Entity\ContentBody;
use CloudPlayDev\ConfluenceClient\Entity\ContentPage;
use CloudPlayDev\ConfluenceClient\Entity\ContentSearchResult;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Common\HttpMethodsClientInterface;
use Http\Client\Exception;
use Http\Client\Exception\TransferException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ConfluenceClientTest extends TestCase
{
    /**
     * What: A requested page is converted from storage content to export-view HTML.
     * How: SDK doubles return a page and rendered body while all conversion arguments are verified.
     * Why: The adapter must supply Markdown conversion with rendered HTML rather than storage markup.
     */
    public function testItFetchesAndRendersAPage(): void
    {
        $page = (new ContentPage())
            ->setId(123)
            ->setTitle('Architecture')
            ->setContent('<ac:structured-macro />');

        $renderedBody = (new ContentBody())
            ->setRepresentation(ContentBody::REPRESENTATION_EXPORT_VIEW)
            ->setValue('<h1>Architecture</h1>');

        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->expects(self::once())
            ->method('find')
            ->with(['id' => '123'], 1)
            ->willReturn((new ContentSearchResult())->setResults([$page]));
        $contentApi
            ->expects(self::once())
            ->method('convert')
            ->with(
                self::callback(static function (ContentBody $body): bool {
                    return $body->getRepresentation() === ContentBody::REPRESENTATION_STORAGE
                        && $body->getValue() === '<ac:structured-macro />';
                }),
                ContentBody::REPRESENTATION_EXPORT_VIEW,
                $page,
            )
            ->willReturn($renderedBody);

        $client = $this->createClient($contentApi);

        $result = $client->getPage('123');

        self::assertSame($page, $result);
        self::assertSame('<h1>Architecture</h1>', $result->getContent());
    }

    /**
     * What: Null source content is converted as an empty storage body.
     * How: A page without content is returned and the conversion body is inspected.
     * Why: Incomplete Confluence responses must not cause a string type error.
     */
    public function testItHandlesNullPageContent(): void
    {
        $page = (new ContentPage())->setId(123)->setTitle('Empty');
        $renderedBody = (new ContentBody())->setValue('');

        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->method('find')
            ->willReturn((new ContentSearchResult())->setResults([$page]));
        $contentApi
            ->expects(self::once())
            ->method('convert')
            ->with(
                self::callback(
                    static fn(ContentBody $body): bool => $body->getValue() === '',
                ),
                ContentBody::REPRESENTATION_EXPORT_VIEW,
                $page,
            )
            ->willReturn($renderedBody);

        $result = $this->createClient($contentApi)->getPage('123');

        self::assertSame('', $result->getContent());
    }

    public function testItLooksUpPageIdsBeyondThePhpIntegerRangeAsStrings(): void
    {
        $pageId = '999999999999999999999999';
        $page = (new ContentPage())->setId($pageId)->setTitle('Large ID')->setContent('Storage');
        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->expects(self::once())
            ->method('find')
            ->with(['id' => $pageId], 1)
            ->willReturn((new ContentSearchResult())->setResults([$page]));
        $contentApi
            ->expects(self::once())
            ->method('convert')
            ->willReturn((new ContentBody())->setValue('Rendered'));

        $result = $this->createClient($contentApi)->getPage($pageId);

        self::assertSame($pageId, $result->getId());
        self::assertSame('Rendered', $result->getContent());
    }

    /**
     * What: A missing SDK page produces a clear adapter exception.
     * How: The SDK returns null for a page lookup and the resulting message is asserted.
     * Why: Callers need a domain-relevant failure instead of a null dereference.
     */
    public function testItReportsAMissingPage(): void
    {
        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->expects(self::once())
            ->method('find')
            ->with(['id' => '404'], 1)
            ->willReturn(new ContentSearchResult());

        $this->expectException(ConfluencePhpClientException::class);
        $this->expectExceptionMessage('Confluence page 404 was not found.');

        $this->createClient($contentApi)->getPage('404');
    }

    /**
     * What: HTTP client failures are translated while retaining their original cause.
     * How: The SDK throws a transfer exception and the adapter exception chain is inspected.
     * Why: Application callers need one exception type without losing diagnostic context.
     */
    public function testItTranslatesHttpClientFailures(): void
    {
        $httpException = new TransferException('Network unavailable.');
        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->expects(self::once())
            ->method('find')
            ->willThrowException($httpException);

        try {
            $this->createClient($contentApi)->getPage('123');
            self::fail('Expected the adapter to translate the HTTP exception.');
        } catch (ConfluencePhpClientException $exception) {
            self::assertSame('Network unavailable.', $exception->getMessage());
            self::assertSame($httpException, $exception->getPrevious());
        }
    }

    public function testItFetchesEveryPageOfChildReferencesInApiOrder(): void
    {
        $requestedUris = [];
        $responses = [
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => '999999999999999999999', 'type' => 'page', 'title' => 'First'],
                    ['id' => '2', 'type' => 'page', 'title' => 'Second'],
                ],
                '_links' => ['next' => '/rest/api/content/1/child/page?start=2'],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => '3', 'type' => 'page', 'title' => 'Third'],
                ],
                '_links' => [],
            ], JSON_THROW_ON_ERROR)),
        ];
        $httpClient = $this->createMock(HttpMethodsClientInterface::class);
        $httpClient
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(static function (string $uri) use (&$requestedUris, &$responses): Response {
                $requestedUris[] = $uri;
                $response = array_shift($responses);

                if ($response === null) {
                    throw new \LogicException('No child-page response was configured.');
                }

                return $response;
            });

        $children = $this->createClientWithHttpClient($httpClient)->getChildPages('1');

        self::assertSame(
            [
                '/rest/api/content/1/child/page?start=0&limit=100',
                '/rest/api/content/1/child/page?start=2&limit=100',
            ],
            $requestedUris,
        );
        self::assertSame(
            ['999999999999999999999', '2', '3'],
            array_map(static fn($child): string => $child->id, $children),
        );
        self::assertSame(['First', 'Second', 'Third'], array_map(static fn($child): ?string => $child->title, $children));
    }

    public function testItRejectsAnInvalidChildPageId(): void
    {
        $httpClient = $this->createStub(HttpMethodsClientInterface::class);
        $httpClient->method('get')->willReturn(new Response(200, [], json_encode([
            'results' => [['id' => 'invalid', 'type' => 'page', 'title' => 'Broken']],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(ConfluencePhpClientException::class);
        $this->expectExceptionMessage('invalid child page ID');

        $this->createClientWithHttpClient($httpClient)->getChildPages('1');
    }

    /**
     * What: Confluence attachments are downloaded from the Confluence API.
     * How: The Confluence API is queried for the attachment contents.
     * Why: Imported pages must be usable as Markdown rather than retaining Confluence HTML.
     * @throws Exception
     * @throws ConfluencePhpClientException
     */
    public function testItDownloadsRawAttachmentContents(): void
    {
        // arrange
        $contents = "\xFF\xD8\xFFexample-jpeg-data";
        $attachment = new ContentAttachment()
            ->setDownloadUrl('/download/attachments/123/photo.jpg');
        $httpClient = $this->createMock(HttpMethodsClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('get')
            ->with('/download/attachments/123/photo.jpg')
            ->willReturn(new Response(200, [], $contents));

        // act
        $result = $this->createClientWithHttpClient($httpClient)
            ->downloadAttachment($attachment);

        // assert
        self::assertSame($contents, $result);
    }

    /**
     * What: Confluence attachments are downloaded from the Confluence API.
     * How: The Confluence API is queried for the attachment contents.
     * Why: Imported pages must be usable as Markdown rather than retaining Confluence HTML.
     * @throws Exception
     */
    public function testItReportsAMissingAttachmentDownloadUrl(): void
    {
        // assert
        $this->expectException(ConfluencePhpClientException::class);
        $this->expectExceptionMessageIsOrContains('does not contain a download URL');

        // act
        $this->createClient($this->createStub(Content::class))
            ->downloadAttachment(new ContentAttachment());
    }

    /**
     * What: Confluence attachments are downloaded from the Confluence API.
     * How: The Confluence API is queried for the attachment contents.
     * Why: Imported pages must be usable as Markdown rather than retaining Confluence HTML.
     * @throws Exception
     */
    public function testItReportsAnUnsuccessfulAttachmentDownload(): void
    {
        // arrange
        $attachment = new ContentAttachment()->setDownloadUrl('/missing.jpg');
        $httpClient = $this->createStub(HttpMethodsClientInterface::class);
        $httpClient->method('get')->willReturn(new Response(404));

        // assert
        $this->expectException(ConfluencePhpClientException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessageIsOrContains('The endpoint you have tried to access does not exist.');

        // act
        $this->createClientWithHttpClient($httpClient)
            ->downloadAttachment($attachment);
    }

    private function createClient(Content $contentApi): ConfluenceClient
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('content')->willReturn($contentApi);

        $factory = $this->createStub(
            ConfluenceSdkClientFactoryInterface::class,
        );
        $factory->method('create')->willReturn($sdkClient);

        return new ConfluenceClient($factory);
    }

    private function createClientWithHttpClient(
        HttpMethodsClientInterface $httpClient,
    ): ConfluenceClient {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('getHttpClient')->willReturn($httpClient);

        $factory = $this->createStub(ConfluenceSdkClientFactoryInterface::class);
        $factory->method('create')->willReturn($sdkClient);

        return new ConfluenceClient($factory);
    }
}
