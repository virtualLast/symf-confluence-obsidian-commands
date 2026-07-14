<?php

namespace App\Confluence;

use DOMXPath;

final class ConfluenceHtmlCleaner extends AbstractConfluenceDomDocument
{

    /**
     * Removes Confluence-specific presentation elements from rendered HTML.
     */
    public function clean(string $html): string
    {
        $document = $this->loadFragment($html);
        $xpath = new DOMXPath($document);

        // Remove embedded styles that are not useful in Markdown.
        $this->removeElements(
            $xpath,
            '//style',
        );

        // Remove placeholder images generated for missing attachments.
        $this->removeElements(
            $xpath,
            '//img[contains(concat(" ", normalize-space(@class), " "), " unknown-attachment ")]',
        );

        $this->removeElements(
            $xpath,
            '//img[contains(@src, "/placeholder/unknown-attachment")]'
        );

        // Remove the expand/collapse control while preserving the expanded content.
        $this->removeElements(
            $xpath,
            '//*[contains(concat(" ", normalize-space(@class), " "), " expand-control ")]',
        );

        // Remove empty paragraphs that contain no text or child elements.
        $this->removeElements(
            $xpath,
            '//p[not(*) and normalize-space(.) = ""]',
        );

        return $this->innerHtml($document->documentElement);
    }

    /**
     * Removes every element matching the supplied XPath expression.
     */
    private function removeElements(
        DOMXPath $xpath,
        string $expression,
    ): void {
        $nodes = $xpath->query($expression);

        if ($nodes === false) {
            return;
        }

        // Copy the nodes first as we're modifying the DOM during iteration.
        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
