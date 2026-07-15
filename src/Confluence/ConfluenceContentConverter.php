<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Markdown\MarkdownCleaner;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{
    public function __construct(
        private ConfluenceHtmlCleaner $htmlCleaner,
        private ConfluenceHtmlTransformer $htmlTransformer,
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

    public function toMarkdown(string $content): string
    {
        $cleanHtml = $this->htmlCleaner->clean($content);
        $transformedHtml = $this->htmlTransformer->transform($cleanHtml);

        $markdown = $this->htmlConverter->convert($transformedHtml);

        return $this->markdownCleaner->clean($markdown);
    }
}
