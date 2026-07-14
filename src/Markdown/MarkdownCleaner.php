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

        $cleaned = '';
        $insideFence = false;
        $fenceCharacter = null;
        $fenceLength = null;
        $blankLinePending = false;

        foreach ($lines as [$content, $lineEnding]) {
            if ($insideFence) {
                $cleaned .= $content . $lineEnding;

                if (
                    $fenceCharacter !== null
                    && $fenceLength !== null
                    && $this->isClosingFence(
                        $content,
                        $fenceCharacter,
                        $fenceLength,
                    )
                ) {
                    $insideFence = false;
                    $fenceCharacter = null;
                    $fenceLength = null;
                }

                continue;
            }

            $openingFence = $this->openingFence($content);

            if ($openingFence !== null) {
                if ($blankLinePending) {
                    $cleaned .= $blankLinePending;
                    $blankLinePending = false;
                }

                $cleaned .= $content . $lineEnding;

                $insideFence = true;
                $fenceCharacter = $openingFence['character'];
                $fenceLength = $openingFence['length'];

                continue;
            }

            if (trim($content) === '') {
                if ($blankLinePending === false) {
                    $blankLinePending = $content . $lineEnding;
                }

                continue;
            }

            if ($blankLinePending !== false) {
                $cleaned .= $blankLinePending;
                $blankLinePending = false;
            }

            $cleaned .= $content . $lineEnding;
        }

        if ($blankLinePending !== false) {
            $cleaned .= $blankLinePending;
        }

        return $cleaned;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function splitIntoLines(string $markdown): array
    {
        $parts = preg_split(
            '/(\r\n|\n|\r)/',
            $markdown,
            flags: PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return [[$markdown, '']];
        }

        $lines = [];

        for ($index = 0; $index < count($parts); $index += 2) {
            $lines[] = [
                $parts[$index],
                $parts[$index + 1] ?? '',
            ];
        }

        return $lines;
    }

    /**
     * @return array{character: string, length: int}|null
     */
    private function openingFence(string $line): ?array
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

    private function isClosingFence(
        string $line,
        string $fenceCharacter,
        int $minimumLength,
    ): bool {
        $quotedCharacter = preg_quote($fenceCharacter, '/');

        return preg_match(
                sprintf(
                    '/^ {0,3}%s{%d,}[ \t]*$/',
                    $quotedCharacter,
                    $minimumLength,
                ),
                $line,
            ) === 1;
    }
}
