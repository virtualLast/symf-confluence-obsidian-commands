<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Obsidian\ObsidianAttachmentPlaceholderRewriter;
use App\Obsidian\ObsidianAttachmentWriter;
use App\Obsidian\ObsidianPageWriter;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

final readonly class ConfluencePageImportPipeline implements ConfluencePageImportPipelineInterface
{
    public function __construct(
        private ConfluenceImporter $confluenceImporter,
        private ObsidianPageWriter $pageWriter,
        private ObsidianAttachmentWriter $attachmentWriter,
        private ObsidianAttachmentPlaceholderRewriter $attachmentPlaceholderRewriter,
    ) {}

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    public function import(
        string $pageId,
        string $destination = '',
        bool $withAttachments = false,
    ): CompletedConfluencePageImport {
        $page = $this->confluenceImporter->importPage($pageId, $withAttachments);

        try {
            $this->pageWriter->validateDestination($destination);
        } catch (IOExceptionInterface|InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to write the imported page: %s',
                $exception->getMessage(),
            ), previous: $exception);
        }

        try {
            foreach ($page->attachments as $attachment) {
                $this->attachmentWriter->write($page, $attachment);
            }
        } catch (IOExceptionInterface|InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to write an imported attachment: %s',
                $exception->getMessage(),
            ), previous: $exception);
        }

        try {
            $filePath = $this->pageWriter->write(
                $this->attachmentPlaceholderRewriter->rewrite($page),
                $destination,
            );
        } catch (IOExceptionInterface|InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to write the imported page: %s',
                $exception->getMessage(),
            ), previous: $exception);
        }

        return new CompletedConfluencePageImport($page, $filePath);
    }
}
