<?php

namespace App\Confluence;

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

        $this->wrapTopLevelAnchors($xpath);

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
}
