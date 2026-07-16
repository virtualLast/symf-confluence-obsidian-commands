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
            ->method('get')
            ->with(123)
            ->willReturn($page);
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

        $result = $client->getPage(123);

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
        $contentApi->method('get')->willReturn($page);
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

        $result = $this->createClient($contentApi)->getPage(123);

        self::assertSame('', $result->getContent());
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
            ->method('get')
            ->with(404)
            ->willReturn(null);

        $this->expectException(ConfluencePhpClientException::class);
        $this->expectExceptionMessage('Confluence page 404 was not found.');

        $this->createClient($contentApi)->getPage(404);
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
            ->method('get')
            ->willThrowException($httpException);

        try {
            $this->createClient($contentApi)->getPage(123);
            self::fail('Expected the adapter to translate the HTTP exception.');
        } catch (ConfluencePhpClientException $exception) {
            self::assertSame('Network unavailable.', $exception->getMessage());
            self::assertSame($httpException, $exception->getPrevious());
        }
    }

    /**
     * What: Child-page requests use the SDK page-child endpoint and page content type.
     * How: The page and expected type are asserted against a mocked children response.
     * Why: Child discovery must not accidentally request attachments or another content type.
     */
    public function testItFetchesPageChildren(): void
    {
        $page = (new ContentPage())->setId(123);
        $children = new ContentSearchResult();
        $contentApi = $this->createMock(Content::class);
        $contentApi
            ->expects(self::once())
            ->method('children')
            ->with($page, Content::CONTENT_TYPE_PAGE)
            ->willReturn($children);

        $result = $this->createClient($contentApi)->getPageChildren($page);

        self::assertSame($children, $result);
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
