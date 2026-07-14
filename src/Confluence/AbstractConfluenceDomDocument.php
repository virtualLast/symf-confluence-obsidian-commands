<?php

namespace App\Confluence;

use DOMDocument;
use DOMElement;

class AbstractConfluenceDomDocument
{

    protected const string FRAGMENT_ROOT_ID = 'confluence-root';

    /**
     * Parses an HTML fragment into a DOMDocument.
     */
    protected function loadFragment(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previousErrorHandling = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                sprintf(
                    '<?xml encoding="UTF-8">'
                    . '<!DOCTYPE html>'
                    . '<html>'
                    . '<body>'
                    . '<div id="%s">%s</div>'
                    . '</body>'
                    . '</html>',
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
     * Returns the transformed HTML without the temporary wrapper.
     */
    protected function innerHtml(?DOMElement $documentElement): string
    {
        if ($documentElement === null) {
            return '';
        }

        $fragmentRoot = null;

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
