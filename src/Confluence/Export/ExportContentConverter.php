<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

final readonly class ExportContentConverter
{
    public function __construct(private ExportLinkResolver $links) {}

    /** Convert supported Markdown, resolve links, and omit images with warnings. */
    public function convert(ExportNote $note): ConvertedExport
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addInlineParser(new WikiLinkParser(), 250);
        $environment->addInlineParser(new UnsupportedSyntaxParser(), 240);
        $document = (new MarkdownParser($environment))->parse($note->markdown);
        $warnings = [];
        $remove = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            if (!$event->isEntering()) {
                continue;
            }
            $node = $event->getNode();
            if ($node instanceof Image) {
                $warnings[] = 'Skipped image or embed: ' . $node->getUrl();
                $remove[] = $node;
                $walker->resumeAt($node, false);
            } elseif ($node instanceof UnsupportedSyntax) {
                throw new \InvalidArgumentException('Unsupported Obsidian or extended Markdown syntax: ' . $node->literal);
            } elseif ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                throw new \InvalidArgumentException('Raw HTML and Confluence markup are not supported in exported Markdown.');
            } elseif ($node instanceof Link) {
                $url = $node->getUrl();
                if ($node->data->get('obsidian', false)) {
                    $node->setUrl($this->links->resolve($url, $note, true));
                } elseif (preg_match('~^(?:https?://|mailto:)~i', $url) !== 1) {
                    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 || str_starts_with($url, '//')) {
                        throw new \InvalidArgumentException('Unsupported link URL: ' . $url);
                    }
                    $node->setUrl($this->links->resolve($url, $note));
                }
            } elseif ($node instanceof Text && preg_match('/\[\[|^\[[ xX]\] /', $node->getLiteral()) === 1) {
                throw new \InvalidArgumentException('Unsupported Obsidian or extended Markdown syntax: ' . $node->getLiteral());
            }
        }
        foreach ($remove as $node) {
            $node->detach();
        }

        return new ConvertedExport((string) (new HtmlRenderer($environment))->renderDocument($document), $warnings);
    }
}
