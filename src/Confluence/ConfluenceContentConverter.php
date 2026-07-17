<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Markdown\MarkdownCleaner;
use App\Model\ImportedAttachment;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{
    public function __construct(
        private ConfluenceHtmlCleaner $htmlCleaner,
        private ConfluenceHtmlTransformer $htmlTransformer,
        private ConfluenceImagePlaceholderTransformer $imagePlaceholderTransformer,
        private ConfluenceAttachmentListPlaceholderTransformer $attachmentListPlaceholderTransformer,
        private HtmlConverter $htmlConverter,
        private MarkdownCleaner $markdownCleaner,
    ) {
        $this->htmlConverter
            ->getEnvironment()
            ->addConverter(
                new TableConverter()
            )
        ;
    }

    /** @param list<ImportedAttachment> $attachments */
    public function toMarkdown(string $content, array $attachments = []): string
    {
        $cleanHtml = $this->htmlCleaner->clean($content);
        $transformedHtml = $this->htmlTransformer->transform($cleanHtml);
        $transformedHtml = $this->imagePlaceholderTransformer->transform(
            $transformedHtml,
            $attachments,
        );
        $transformedHtml = $this->attachmentListPlaceholderTransformer->transform($transformedHtml);

        $markdown = $this->htmlConverter->convert($transformedHtml);
        // league/html-to-markdown escapes underscores in text. Restore only
        // our exact sentinel so it remains stable for the Obsidian stage.
        $markdown = preg_replace_callback(
            '/@@OBSIDIAN\\\\_ATTACHMENT(?::(att[^@\s]+)|\\\\_LIST)@@/',
            static fn(array $matches): string => isset($matches[1])
                ? '@@OBSIDIAN_ATTACHMENT:' . $matches[1] . '@@'
                : ConfluenceAttachmentListPlaceholderTransformer::PLACEHOLDER,
            $markdown,
        ) ?? $markdown;

        return $this->markdownCleaner->clean($markdown);
    }
}
