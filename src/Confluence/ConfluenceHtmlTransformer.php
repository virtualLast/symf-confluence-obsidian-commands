<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Confluence\CodeBlock\CodeBlockLanguageDetectorInterface;
use DOMElement;
use DOMNode;
use DOMXPath;

final class ConfluenceHtmlTransformer extends AbstractConfluenceDomDocument
{
    public function __construct(
        private readonly CodeBlockLanguageDetectorInterface $codeBlockLanguageDetector,
    ) {}

    /**
     * Transforms rendered Confluence HTML into a structure better suited
     * to Markdown conversion.
     */
    public function transform(string $html): string
    {
        $document = $this->loadFragment($html);
        $xpath = new DOMXPath($document);

        $this->transformCodeBlocks($xpath);
        $this->normaliseNestedLists($xpath);
        $this->wrapStandaloneImages($xpath);
        $this->wrapTopLevelAnchors($xpath);
        $this->wrapStandaloneLinks($xpath);

        return $this->innerHtml($document->documentElement);
    }

    private function transformCodeBlocks(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//pre[@data-syntaxhighlighter-params]');

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $preformattedCode) {
            if (!$preformattedCode instanceof DOMElement) {
                continue;
            }

            $declaredLanguage = $this->languageFromSyntaxHighlighterParameters(
                $preformattedCode->getAttribute('data-syntaxhighlighter-params'),
            );

            $language = $this->codeBlockLanguageDetector->detect(
                $declaredLanguage,
                $preformattedCode->textContent,
            );
            $this->transformCodeBlock($preformattedCode, $language);
        }
    }

    private function languageFromSyntaxHighlighterParameters(string $parameters): ?string
    {
        if (preg_match('/(?:^|;)\s*brush\s*:\s*([^;\s]+)/i', $parameters, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function transformCodeBlock(DOMElement $preformattedCode, ?string $language): void
    {
        $document = $preformattedCode->ownerDocument;

        if ($document === null) {
            return;
        }

        $semanticPre = $document->createElement('pre');
        $semanticCode = $document->createElement('code');
        if ($language !== null) {
            $semanticCode->setAttribute('class', 'language-' . $language);
        }

        while ($preformattedCode->firstChild !== null) {
            $semanticCode->appendChild($preformattedCode->firstChild);
        }

        $semanticPre->appendChild($semanticCode);
        $replacementTarget = $this->codePanelContaining($preformattedCode) ?? $preformattedCode;
        $replacementTarget->parentNode?->replaceChild($semanticPre, $replacementTarget);
    }

    private function codePanelContaining(DOMElement $preformattedCode): ?DOMElement
    {
        $ancestor = $preformattedCode->parentNode;

        while ($ancestor instanceof DOMElement) {
            if ($this->hasClass($ancestor, 'code') && $this->hasClass($ancestor, 'panel')) {
                return $ancestor;
            }

            $ancestor = $ancestor->parentNode;
        }

        return null;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
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

    private function normaliseNestedLists(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//li[ul or ol]');

        if ($nodes === false) {
            return;
        }

        foreach (array_reverse(iterator_to_array($nodes)) as $listItem) {
            if (!$listItem instanceof DOMElement) {
                continue;
            }

            if (!$this->isEmptyListWrapper($listItem)) {
                continue;
            }

            $this->unwrapEmptyListItem($listItem);
        }
    }

    private function isEmptyListWrapper(DOMElement $listItem): bool
    {
        $containsNestedList = false;

        foreach ($listItem->childNodes as $childNode) {
            if ($this->isList($childNode)) {
                $containsNestedList = true;
                continue;
            }

            if ($this->containsMeaningfulContent($childNode)) {
                return false;
            }
        }

        return $containsNestedList;
    }

    private function unwrapEmptyListItem(DOMElement $listItem): void
    {
        $parentList = $listItem->parentNode;

        if (!$parentList instanceof DOMElement || !$this->isList($parentList)) {
            return;
        }

        $previousListItem = $this->previousListItem($listItem);

        if ($previousListItem !== null) {
            $this->moveNestedLists($listItem, $previousListItem);
            $parentList->removeChild($listItem);

            return;
        }

        $this->promoteNestedListItems($listItem, $parentList);
        $parentList->removeChild($listItem);
    }

    private function previousListItem(DOMElement $listItem): ?DOMElement
    {
        $sibling = $listItem->previousSibling;

        while ($sibling !== null) {
            if ($sibling instanceof DOMElement) {
                return strtolower($sibling->tagName) === 'li' ? $sibling : null;
            }

            $sibling = $sibling->previousSibling;
        }

        return null;
    }

    private function moveNestedLists(DOMElement $wrapper, DOMElement $destination): void
    {
        foreach (iterator_to_array($wrapper->childNodes) as $childNode) {
            if ($this->isList($childNode)) {
                $destination->appendChild($childNode);
            }
        }
    }

    private function promoteNestedListItems(DOMElement $wrapper, DOMElement $parentList): void
    {
        foreach (iterator_to_array($wrapper->childNodes) as $nestedList) {
            if (!$this->isList($nestedList)) {
                continue;
            }

            foreach (iterator_to_array($nestedList->childNodes) as $nestedListItem) {
                if ($nestedListItem instanceof DOMElement && strtolower($nestedListItem->tagName) === 'li') {
                    $parentList->insertBefore($nestedListItem, $wrapper);
                }
            }
        }
    }

    private function isList(DOMNode $node): bool
    {
        return $node instanceof DOMElement
            && in_array(strtolower($node->tagName), ['ul', 'ol'], true);
    }

    private function containsMeaningfulContent(DOMNode $node): bool
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return trim(str_replace("\u{00A0}", '', $node->textContent)) !== '';
        }

        if (!$node instanceof DOMElement) {
            return false;
        }

        if (!$node->hasChildNodes()) {
            return !in_array(strtolower($node->tagName), ['p', 'span', 'div', 'br'], true);
        }

        foreach ($node->childNodes as $childNode) {
            if ($this->containsMeaningfulContent($childNode)) {
                return true;
            }
        }

        return false;
    }

    private function wrapStandaloneImages(DOMXPath $xpath): void
    {
        $nodes = $xpath->query(
            sprintf(
                '//*[@id="%s"]/*[self::img or self::span[descendant::img]]',
                self::FRAGMENT_ROOT_ID,
            ),
        );

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $imageContent) {
            if (!$imageContent instanceof DOMElement) {
                continue;
            }

            $this->wrapElementInParagraph($imageContent);
        }
    }

    private function wrapElementInParagraph(DOMElement $element): void
    {
        $parent = $element->parentNode;
        $paragraph = $element->ownerDocument?->createElement('p');

        if ($parent === null || $paragraph === null) {
            return;
        }

        $parent->replaceChild($paragraph, $element);
        $paragraph->appendChild($element);
    }
}
