<?php

namespace App\Markdown;

final class MarkdownCleaner
{
    public function clean(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $lines = $this->splitIntoLines($markdown);
        $cleanedLines = $this->collapseExcessiveBlankLines($lines);

        return implode('', $cleanedLines);
    }

    /**
     * Splits Markdown into complete lines while retaining line endings.
     *
     * Retaining the line endings allows the cleaner to reconstruct the input
     * without changing LF or CRLF formatting.
     *
     * @return list<string>
     */
    private function splitIntoLines(string $markdown): array
    {
        $lines = preg_split(
            '/(?<=\r\n)|(?<=\n)|(?<=\r)/',
            $markdown,
        );

        if ($lines === false) {
            return [$markdown];
        }

        return array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => $line !== '',
            ),
        );
    }

    /**
     * Collapses consecutive blank lines outside fenced code blocks.
     *
     * The first blank line is retained. Any further consecutive blank lines
     * are discarded. Content inside fenced code blocks is copied unchanged.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function collapseExcessiveBlankLines(array $lines): array
    {
        $cleanedLines = [];
        $pendingBlankLine = null;
        $openFence = null;

        foreach ($lines as $line) {
            if ($openFence !== null) {
                $cleanedLines[] = $line;

                if ($this->closesFence($line, $openFence)) {
                    $openFence = null;
                }

                continue;
            }

            $openingFence = $this->findOpeningFence($line);

            if ($openingFence !== null) {
                $this->appendPendingBlankLine(
                    $cleanedLines,
                    $pendingBlankLine,
                );

                $cleanedLines[] = $line;
                $openFence = $openingFence;

                continue;
            }

            if ($this->isBlankLine($line)) {
                // Retain only the first blank line in a consecutive sequence.
                $pendingBlankLine ??= $line;

                continue;
            }

            $this->appendPendingBlankLine(
                $cleanedLines,
                $pendingBlankLine,
            );

            $cleanedLines[] = $line;
        }

        $this->appendPendingBlankLine(
            $cleanedLines,
            $pendingBlankLine,
        );

        return $cleanedLines;
    }

    /**
     * @param list<string> $cleanedLines
     */
    private function appendPendingBlankLine(
        array &$cleanedLines,
        ?string &$pendingBlankLine,
    ): void {
        if ($pendingBlankLine === null) {
            return;
        }

        $cleanedLines[] = $pendingBlankLine;
        $pendingBlankLine = null;
    }

    private function isBlankLine(string $line): bool
    {
        return trim($line) === '';
    }

    /**
     * @return array{character: string, length: int}|null
     */
    private function findOpeningFence(string $line): ?array
    {
        if (
            preg_match(
                '/^ {0,3}(`{3,}|~{3,})/',
                $line,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return [
            'character' => $matches[1][0],
            'length' => strlen($matches[1]),
        ];
    }

    /**
     * @param array{character: string, length: int} $openFence
     */
    private function closesFence(
        string $line,
        array $openFence,
    ): bool {
        $character = preg_quote(
            $openFence['character'],
            '/',
        );

        return preg_match(
                sprintf(
                    '/^ {0,3}%s{%d,}[ \t]*(?:\r\n|\n|\r)?$/',
                    $character,
                    $openFence['length'],
                ),
                $line,
            ) === 1;
    }
}
