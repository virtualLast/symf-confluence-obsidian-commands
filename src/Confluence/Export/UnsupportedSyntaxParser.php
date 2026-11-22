<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

final class UnsupportedSyntaxParser implements InlineParserInterface
{
    /** Detect unsupported delimiters before nested inline formatting splits them. */
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('==[^\r\n]+?==|~~[^\r\n]+?~~|%%|\$\$|\[\^[^\]]+\]|\[![^\]]+\]');
    }

    /** Mark unsupported content for validation after image subtrees are excluded. */
    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getContainer()->appendChild(new UnsupportedSyntax($inlineContext->getFullMatch()));
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());

        return true;
    }
}
