<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\StringContainerInterface;

final class ExpandCalloutTransformer
{
    /** Replace only explicitly collapsible callouts; leave ordinary quotes unchanged. */
    public function transform(Document $document): void
    {
        $quotes = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            if ($event->isEntering() && $event->getNode() instanceof BlockQuote) {
                $quotes[] = $event->getNode();
            }
        }
        foreach ($quotes as $quote) {
            $paragraph = $quote->firstChild();
            $marker = $paragraph?->firstChild();
            $titleNode = $marker?->next();
            if (!$paragraph instanceof Paragraph || !$marker instanceof UnsupportedSyntax
                || preg_match('/^\[![a-zA-Z0-9_-]+\]$/', $marker->literal) !== 1
                || !$titleNode instanceof Text || preg_match('/^[+-](?:\s|$)/', $titleNode->getLiteral()) !== 1) {
                continue;
            }
            $titleNode->setLiteral(substr($titleNode->getLiteral(), 1));
            $marker->detach();
            $title = '';
            foreach ($paragraph->children() as $child) {
                if ($child instanceof Newline) {
                    $child->detach();
                    break;
                }
                $titleWalker = $child->walker();
                while (($event = $titleWalker->next()) !== null) {
                    if (!$event->isEntering()) {
                        continue;
                    }
                    $node = $event->getNode();
                    if ($node instanceof StringContainerInterface) {
                        $title .= $node->getLiteral();
                    } elseif ($node instanceof UnsupportedSyntax) {
                        $title .= $node->literal;
                    }
                }
                $child->detach();
            }
            if (!$paragraph->hasChildren()) {
                $paragraph->detach();
            }
            $expand = new ExpandBlock(trim($title) !== '' ? trim($title) : 'Details');
            $quote->insertBefore($expand);
            foreach ($quote->children() as $child) {
                $expand->appendChild($child);
            }
            $quote->detach();
        }
    }
}
