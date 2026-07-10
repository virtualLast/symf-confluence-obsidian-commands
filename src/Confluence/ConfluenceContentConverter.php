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
        return $this->htmlConverter->convert($content);
    }
}
