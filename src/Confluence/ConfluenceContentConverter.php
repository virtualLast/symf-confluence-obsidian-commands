<?php

namespace App\Confluence;

use League\HTMLToMarkdown\HtmlConverter;

final readonly class ConfluenceContentConverter
{
    public function __construct(
        private HtmlConverter $htmlConverter,
    ) {
        $this->htmlConverter->setOptions([
            'header_style' => 'atx',
        ]);
    }

    public function toMarkdown(string $content): string
    {
        return $this->htmlConverter->convert($content);
    }
}
