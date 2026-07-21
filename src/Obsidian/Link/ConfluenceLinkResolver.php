<?php

declare(strict_types=1);

namespace App\Obsidian\Link;

use App\Obsidian\Frontmatter\ConfluenceRelationship;
use App\Obsidian\Index\ConfluencePageIndex;
use App\Obsidian\Index\IndexedConfluencePage;

final readonly class ConfluenceLinkResolver
{
    public function resolve(
        IndexedConfluencePage $source,
        ConfluencePageIndex $index,
        string $content,
    ): ConfluenceLinkResolutionResult {
        $targetsByUrl = [];
        $unresolved = [];

        foreach ($source->relationships as $relationship) {
            $target = $index->find($relationship->pageId);

            if ($target === null) {
                $unresolved[] = new UnresolvedConfluenceRelationship(
                    $source->vaultPath,
                    $relationship->pageId,
                );

                continue;
            }

            $targetsByUrl[$relationship->url] = [$relationship, $target];
        }

        [$frontmatter, $body] = $this->splitFrontmatter($content);
        $linksResolved = 0;
        $updatedBody = $this->rewriteBody($body, $targetsByUrl, $linksResolved);
        $updatedContent = $frontmatter . $updatedBody;

        return new ConfluenceLinkResolutionResult(
            $updatedContent,
            count($source->relationships),
            $linksResolved,
            $unresolved,
            $updatedContent !== $content,
        );
    }

    /** @return array{string, string} */
    private function splitFrontmatter(string $content): array
    {
        if (preg_match('/\A---(?:\r\n|\n).*?(?:\r\n|\n)---(?:\r\n|\n|\z)/s', $content, $matches) !== 1) {
            throw new \UnexpectedValueException('An indexed note no longer contains valid frontmatter.');
        }

        return [$matches[0], substr($content, strlen($matches[0]))];
    }

    /**
     * @param array<string, array{ConfluenceRelationship, IndexedConfluencePage}> $targetsByUrl
     */
    private function rewriteBody(string $body, array $targetsByUrl, int &$linksResolved): string
    {
        if ($targetsByUrl === []) {
            return $body;
        }

        $lines = preg_split('/(?<=\n)/', $body);

        if ($lines === false) {
            return $body;
        }

        $fenceCharacter = null;
        $fenceLength = 0;
        $rewritten = '';

        foreach ($lines as $line) {
            if ($fenceCharacter !== null) {
                $rewritten .= $line;

                if ($this->isClosingFence($line, $fenceCharacter, $fenceLength)) {
                    $fenceCharacter = null;
                    $fenceLength = 0;
                }

                continue;
            }

            $openingFence = $this->openingFence($line);

            if ($openingFence !== null) {
                [$fenceCharacter, $fenceLength] = $openingFence;
                $rewritten .= $line;

                continue;
            }

            $rewritten .= $this->rewriteInline($line, $targetsByUrl, $linksResolved);
        }

        return $rewritten;
    }

    /** @return array{string, int}|null */
    private function openingFence(string $line): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches) !== 1) {
            return null;
        }

        return [$matches[1][0], strlen($matches[1])];
    }

    private function isClosingFence(string $line, string $character, int $minimumLength): bool
    {
        return preg_match(
            sprintf('/^ {0,3}%s{%d,}[ \t]*(?:\r?\n)?$/', preg_quote($character, '/'), $minimumLength),
            $line,
        ) === 1;
    }

    /**
     * @param array<string, array{ConfluenceRelationship, IndexedConfluencePage}> $targetsByUrl
     */
    private function rewriteInline(string $line, array $targetsByUrl, int &$linksResolved): string
    {
        $length = strlen($line);
        $offset = 0;
        $rewritten = '';

        while ($offset < $length) {
            if ($line[$offset] === '`') {
                $runLength = strspn($line, '`', $offset);
                $closingOffset = strpos($line, str_repeat('`', $runLength), $offset + $runLength);

                if ($closingOffset !== false) {
                    $end = $closingOffset + $runLength;
                    $rewritten .= substr($line, $offset, $end - $offset);
                    $offset = $end;

                    continue;
                }
            }

            if (
                $line[$offset] === '['
                && !$this->isEscaped($line, $offset)
                && ($offset === 0 || $line[$offset - 1] !== '!')
            ) {
                $link = $this->parseLink($line, $offset);

                if ($link !== null && isset($targetsByUrl[$link['destination']])) {
                    [$relationship, $target] = $targetsByUrl[$link['destination']];
                    $label = trim($link['label']) === '' ? $relationship->title : $link['label'];
                    $replacement = $label === ''
                        ? sprintf('[[%s]]', $target->wikilinkPath())
                        : sprintf('[[%s|%s]]', $target->wikilinkPath(), $this->escapeAlias($label));

                    $rewritten .= $replacement;
                    $offset = $link['end'];
                    ++$linksResolved;

                    continue;
                }
            }

            $rewritten .= $line[$offset];
            ++$offset;
        }

        return $rewritten;
    }

    /** @return array{label: string, destination: string, end: int}|null */
    private function parseLink(string $line, int $start): ?array
    {
        $labelEnd = $this->findClosingDelimiter($line, $start, '[', ']');

        if ($labelEnd === null || ($line[$labelEnd + 1] ?? null) !== '(') {
            return null;
        }

        $destinationEnd = $this->findClosingDelimiter($line, $labelEnd + 1, '(', ')');

        if ($destinationEnd === null) {
            return null;
        }

        $inside = trim(substr($line, $labelEnd + 2, $destinationEnd - $labelEnd - 2));
        $destination = $this->destination($inside);

        if ($destination === null) {
            return null;
        }

        return [
            'label' => substr($line, $start + 1, $labelEnd - $start - 1),
            'destination' => $destination,
            'end' => $destinationEnd + 1,
        ];
    }

    private function findClosingDelimiter(
        string $value,
        int $openingOffset,
        string $opening,
        string $closing,
    ): ?int {
        $depth = 0;
        $length = strlen($value);

        for ($offset = $openingOffset; $offset < $length; ++$offset) {
            if ($this->isEscaped($value, $offset)) {
                continue;
            }

            if ($value[$offset] === $opening) {
                ++$depth;
            } elseif ($value[$offset] === $closing && --$depth === 0) {
                return $offset;
            }
        }

        return null;
    }

    private function destination(string $inside): ?string
    {
        if ($inside === '') {
            return null;
        }

        if ($inside[0] === '<') {
            $end = strpos($inside, '>');

            return $end === false ? null : substr($inside, 1, $end - 1);
        }

        $length = strlen($inside);
        $depth = 0;

        for ($offset = 0; $offset < $length; ++$offset) {
            if ($this->isEscaped($inside, $offset)) {
                continue;
            }

            if ($inside[$offset] === '(') {
                ++$depth;
            } elseif ($inside[$offset] === ')') {
                --$depth;
            } elseif (ctype_space($inside[$offset]) && $depth === 0) {
                return substr($inside, 0, $offset);
            }
        }

        return $inside;
    }

    private function isEscaped(string $value, int $offset): bool
    {
        $backslashes = 0;

        for (--$offset; $offset >= 0 && $value[$offset] === '\\'; --$offset) {
            ++$backslashes;
        }

        return $backslashes % 2 === 1;
    }

    private function escapeAlias(string $label): string
    {
        return preg_replace('/(?<!\\\\)\|/', '\\\\|', $label) ?? $label;
    }
}
