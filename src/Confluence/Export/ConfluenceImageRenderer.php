<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ConfluenceImageRenderer implements NodeRendererInterface
{
    /** Emit Confluence attachment or external URL image storage, never local file paths. */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (!$node instanceof Image) {
            throw new \InvalidArgumentException('Expected an image.');
        }
        $alt = '';
        $walker = $node->walker();
        while (($event = $walker->next()) !== null) {
            if ($event->isEntering() && $event->getNode() instanceof StringContainerInterface) {
                $alt .= $event->getNode()->getLiteral();
            }
        }
        $attributes = $alt === '' ? '' : ' ac:alt="' . $this->escape($alt) . '"';
        $size = $node->data->get('obsidian_size', '');
        if (is_string($size) && preg_match('/^([1-9]\d*)(?:x([1-9]\d*))?$/', $size, $matches) === 1) {
            $attributes .= ' ac:width="' . $matches[1] . '"';
            if (isset($matches[2])) {
                $attributes .= ' ac:height="' . $matches[2] . '"';
            }
        }
        $filename = $node->data->get('confluence_filename', null);
        $reference = is_string($filename)
            ? '<ri:attachment ri:filename="' . $this->escape($filename) . '" />'
            : '<ri:url ri:value="' . $this->escape($node->getUrl()) . '" />';

        return '<ac:image' . $attributes . '>' . $reference . '</ac:image>';
    }

    /** Escape arbitrary values for XML attributes. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
