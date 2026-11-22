<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

final class WikiLinkParser implements InlineParserInterface
{
    /** Recognise Obsidian links and embeds outside code spans and code blocks. */
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('!?\[\[[^\]\r\n]+\]\]');
    }

    /** Preserve targets for resolution after Markdown parsing. */
    public function parse(InlineParserContext $inlineContext): bool
    {
        $match = $inlineContext->getFullMatch();
        $embed = str_starts_with($match, '!');
        $inner = substr($match, $embed ? 3 : 2, -2);
        $parts = explode('|', $inner, 2);
        $target = $parts[0];
        $label = $parts[1] ?? null;
        $node = $embed ? new Image($target) : new Link($target, $label ?? $target);
        $node->data->set('obsidian', true);
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild($node);

        return true;
    }
}
