<?php

namespace App\Confluence;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{

    public function __construct(
        private ConfluenceHtmlCleaner $htmlCleaner,
        private HtmlConverter $htmlConverter,
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
        $markdown = $this->htmlConverter->convert($cleanHtml);

        return $this->removeLinesWithEmptySpace($markdown);
    }

    // todo move this into the html cleaner
    private function removeLinesWithEmptySpace(string $markdown): string
    {
        return preg_replace('/^[\t ]+$/m', '', $markdown) ?? $markdown;
    }
}
