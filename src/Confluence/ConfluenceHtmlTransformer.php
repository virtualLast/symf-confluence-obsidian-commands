<?php

declare(strict_types=1);

namespace App\Confluence;

use DOMNode;
use DOMXPath;

final class ConfluenceHtmlTransformer extends AbstractConfluenceDomDocument
{
    /**
     * Transforms rendered Confluence HTML into a structure better suited
     * to Markdown conversion.
     */
    public function transform(string $html): string
    {
        $document = $this->loadFragment($html);
        $xpath = new DOMXPath($document);

        $this->normaliseNestedLists();
        $this->wrapStandaloneImages();
        $this->wrapTopLevelAnchors($xpath);
        $this->wrapStandaloneLinks($xpath);

        return $this->innerHtml($document->documentElement);
    }

    /**
     * Wraps standalone top-level links in paragraphs.
     */
    private function wrapTopLevelAnchors(DOMXPath $xpath): void
    {
        $nodes = $xpath->query(
            sprintf(
                '//*[@id="%s"]/a',
                self::FRAGMENT_ROOT_ID,
            ),
        );

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $anchor) {
            if (!$anchor instanceof DOMNode) {
                continue;
            }

            $parent = $anchor->parentNode;

            if ($parent === null) {
                continue;
            }

            $paragraph = $anchor->ownerDocument?->createElement('p');

            if ($paragraph === null) {
                continue;
            }

            $parent->replaceChild($paragraph, $anchor);
            $paragraph->appendChild($anchor);
        }
    }

    /**
     * Wraps links that are direct children of div elements in paragraphs.
     */
    private function wrapStandaloneLinks(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//div/a');

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $link) {
            if (!$link instanceof DOMNode) {
                continue;
            }

            $parent = $link->parentNode;
            $document = $link->ownerDocument;

            if ($parent === null || $document === null) {
                continue;
            }

            $paragraph = $document->createElement('p');

            $parent->replaceChild($paragraph, $link);
            $paragraph->appendChild($link);
        }
    }

    private function normaliseNestedLists(): void
    {
    }

    private function wrapStandaloneImages(): void
    {

    }
}
