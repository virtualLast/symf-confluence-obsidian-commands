<?php

namespace App\Confluence;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{
    public function __construct(
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
        $markdown = $this->htmlConverter->convert($content);

        return $this->removeLinesWithEmptySpace($markdown);
    }

    private function removeLinesWithEmptySpace(string $markdown): string
    {
        return preg_replace('/^[\t ]+$/m', '', $markdown) ?? $markdown;
    }
}
