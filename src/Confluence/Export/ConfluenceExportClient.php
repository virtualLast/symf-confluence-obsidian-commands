<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use App\Confluence\ConfluenceRequestException;
use App\Confluence\ConfluenceSdkClientFactoryInterface;
use App\Model\ConfluenceId;
use VirtualLast\ConfluenceV2\Exception\ApiException;
use VirtualLast\ConfluenceV2\Exception\TransportException;
use VirtualLast\ConfluenceV2\Generated\Model\PagesIdPutBody;
use VirtualLast\ConfluenceV2\Generated\Model\PagesIdPutBodyVersion;

final readonly class ConfluenceExportClient implements ConfluenceExportGatewayInterface
{
    public function __construct(private ConfluenceSdkClientFactoryInterface $factory) {}

    /** Read storage markup so preview includes macros and other remote-only content. */
    public function getPage(string $pageId): ExportPage
    {
        try {
            $page = $this->factory->create()->pages()->getPageById(ConfluenceId::toInt($pageId), ['body-format' => 'storage']);
            if ($page->getId() !== $pageId || $page->getStatus() !== 'current') {
                throw new \UnexpectedValueException('The destination must be an existing published Confluence page.');
            }
            if (!$page->isInitialized('body') || !$page->getBody()->isInitialized('storage') || !$page->isInitialized('version')) {
                throw new \UnexpectedValueException('The Confluence response is missing its storage body or version.');
            }
            $version = $page->getVersion()->getNumber();
            if ($version < 1) {
                throw new \UnexpectedValueException('The Confluence response contains an invalid version.');
            }

            return new ExportPage($pageId, $page->getTitle(), $page->getBody()->getStorage()->getValue(), $version);
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    /** Publish without changing the title, location, or attached files, and never retry. */
    public function publish(ExportPage $page, string $storage): int
    {
        try {
            $body = (new PagesIdPutBody())
                ->setId($page->id)
                ->setStatus('current')
                ->setTitle($page->title)
                // The generated union normalizer passes body through without normalizing model objects.
                ->setBody(['representation' => 'storage', 'value' => $storage])
                ->setVersion((new PagesIdPutBodyVersion())->setNumber($page->version + 1));
            $result = $this->factory->create()->pages()->updatePage(ConfluenceId::toInt($page->id), $body);
            if ($result->getId() !== $page->id || $result->getVersion()->getNumber() !== $page->version + 1) {
                throw new \UnexpectedValueException('The publish response could not be verified; inspect the destination before retrying.');
            }

            return $result->getVersion()->getNumber();
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    /** Translate SDK errors without retrying a potentially completed write. */
    private function translate(\Throwable $exception): ConfluenceRequestException
    {
        $code = $exception instanceof ApiException ? $exception->statusCode : 0;
        $message = match (true) {
            $code === 409 => 'Confluence changed during publication. Run the command again to fetch its latest version.',
            $exception instanceof TransportException => 'The Confluence request could not be completed. If publishing, check the destination before retrying.',
            default => $exception->getMessage(),
        };

        return new ConfluenceRequestException($message, $code, $exception);
    }
}
