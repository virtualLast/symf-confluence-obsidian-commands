<?php

declare(strict_types=1);

namespace App\Confluence;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

final class ExpandHtmlConverter implements ConverterInterface
{
    /** Convert annotated Expand containers after their child Markdown is ready. */
    public function convert(ElementInterface $element): string
    {
        $title = $element->getAttribute('data-obsidian-expand-title');
        if ($title === '') {
            return $element->getValue() . "\n\n";
        }
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = preg_replace('/([\\\\`*_{}\[\]<>#|!~$%=])/', '\\\\$1', $title) ?? $title;
        $lines = explode("\n", trim($element->getValue()));

        return '> [!note]- ' . $title . "\n>\n"
            . implode("\n", array_map(static fn(string $line): string => '> ' . $line, $lines)) . "\n\n";
    }

    /** @return list<string> */
    public function getSupportedTags(): array
    {
        return ['div'];
    }
}
