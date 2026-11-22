<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ExpandBlockRenderer implements NodeRendererInterface
{
    /** Render native Confluence Expand storage with an escaped title and rich body. */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (!$node instanceof ExpandBlock) {
            throw new \InvalidArgumentException('Expected an Expand block.');
        }

        return '<ac:structured-macro ac:name="expand" ac:schema-version="1"><ac:parameter ac:name="title">'
            . htmlspecialchars($node->title, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '</ac:parameter><ac:rich-text-body>' . $childRenderer->renderNodes($node->children())
            . '</ac:rich-text-body></ac:structured-macro>';
    }
}
