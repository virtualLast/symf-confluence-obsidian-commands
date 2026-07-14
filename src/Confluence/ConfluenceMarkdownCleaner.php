<?php

namespace App\Confluence;

class ConfluenceMarkdownCleaner extends AbstractConfluenceCleaner
{

    public function clean(string $html): string
    {

    }

    protected function removeElements(
        \DOMXPath $xpath,
        string $expression,
    ): void
    {
        // TODO: Implement removeElements() method.
    }
}
