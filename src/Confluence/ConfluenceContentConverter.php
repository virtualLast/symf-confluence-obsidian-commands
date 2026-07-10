<?php

namespace App\Confluence;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{
    public function __construct(
        private ConfluenceStoragePreprocessor $preprocessor,
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
        $html = $this->preprocessor->process($content);

        return $this->htmlConverter->convert($html);
    }
}
