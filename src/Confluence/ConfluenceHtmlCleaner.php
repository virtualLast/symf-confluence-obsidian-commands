<?php

declare(strict_types=1);

namespace App\Confluence;

use DOMElement;
use DOMNode;
use DOMXPath;

final class ConfluenceHtmlCleaner extends AbstractConfluenceCleaner
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

        // Capture titles before removing UI controls, without confusing nested Expands.
        $containers = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " expand-container ")]');
        if ($containers !== false) {
            foreach ($containers as $container) {
                if (!$container instanceof DOMElement) {
                    continue;
                }
                $labels = $xpath->query('./*[contains(concat(" ", normalize-space(@class), " "), " expand-control ")]//*[contains(concat(" ", normalize-space(@class), " "), " expand-control-text ")]', $container);
                $label = $labels !== false ? $labels->item(0) : null;
                $title = $label instanceof DOMNode ? trim($label->textContent) : '';
                $container->setAttribute('data-obsidian-expand-title', $title !== '' ? $title : 'Details');
            }
        }

        // Remove the expand/collapse control while preserving its title and content.
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
    protected function removeElements(
        DOMXPath $xpath,
        string $expression,
    ): void {
        $nodes = $xpath->query($expression);

        if ($nodes === false) {
            return;
        }

        // Copy the nodes first as we're modifying the DOM during iteration.
        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }

            $node->parentNode?->removeChild($node);
        }
    }
}
