<?php

namespace App\Confluence;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class ConfluenceHtmlCleaner
{
    private const string FRAGMENT_ROOT_ID = 'confluence-root';

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

        // Remove the expand/collapse control while preserving the expanded content.
        $this->removeElements(
            $xpath,
            '//*[contains(concat(" ", normalize-space(@class), " "), " expand-control ")]',
        );

        return $this->innerHtml($document->documentElement);
    }

    /**
     * Parses an HTML fragment into a DOMDocument.
     *
     * Confluence returns HTML fragments rather than complete HTML documents,
     * so the fragment is temporarily wrapped in a root element before parsing.
     */
    private function loadFragment(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        // Suppress parser warnings while loading imperfect HTML fragments.
        $previousErrorHandling = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                sprintf(
                    '<!DOCTYPE html><html><body><div id="%s">%s</div></body></html>',
                    self::FRAGMENT_ROOT_ID,
                    $html,
                ),
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }

        return $document;
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

    /**
     * Returns the cleaned HTML without the temporary wrapper element.
     */
    private function innerHtml(?DOMElement $documentElement): string
    {
        if ($documentElement === null) {
            return '';
        }

        $fragmentRoot = null;

        // Locate the temporary wrapper added by loadFragment().
        foreach ($documentElement->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === self::FRAGMENT_ROOT_ID) {
                $fragmentRoot = $div;
                break;
            }
        }

        if ($fragmentRoot === null) {
            return '';
        }

        $html = '';

        foreach ($fragmentRoot->childNodes as $childNode) {
            $html .= $fragmentRoot->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }
}
