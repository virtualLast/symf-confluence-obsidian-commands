<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

use App\Obsidian\Frontmatter\FrontmatterParser;
use App\Obsidian\Frontmatter\ParsedConfluenceFrontmatter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ConfluencePageIndexBuilder
{
    public function __construct(
        private FrontmatterParser $frontmatterParser,
    ) {}

    public function build(ResolvedNotesDirectory $directory): ConfluencePageIndexResult
    {
        $pages = [];
        $duplicates = [];
        $invalidNotes = [];
        $discovered = 0;
        $indexed = 0;
        $skipped = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory->absolutePath,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            ++$discovered;
            $absolutePath = $file->getRealPath();

            if ($absolutePath === false || !$this->isWithin($absolutePath, $directory->absolutePath)) {
                ++$skipped;
                $invalidNotes[$file->getPathname()] = 'The note resolves outside the selected directory.';

                continue;
            }

            $vaultPath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($absolutePath, strlen($directory->vaultPath) + 1),
            );
            $content = $this->readFrontmatter($absolutePath);

            if ($content === false) {
                ++$skipped;
                $invalidNotes[$vaultPath] = 'The note could not be read.';

                continue;
            }

            $frontmatter = $this->frontmatterParser->parse($content);

            if ($frontmatter->status === ParsedConfluenceFrontmatter::STATUS_SKIPPED) {
                ++$skipped;

                continue;
            }

            if ($frontmatter->status === ParsedConfluenceFrontmatter::STATUS_INVALID) {
                ++$skipped;
                $invalidNotes[$vaultPath] = $frontmatter->error ?? 'Invalid frontmatter.';

                continue;
            }

            $pageId = $frontmatter->pageId;

            if ($pageId === null) {
                throw new \LogicException('Valid Confluence frontmatter must contain a page ID.');
            }

            ++$indexed;
            $page = new IndexedConfluencePage(
                $pageId,
                $absolutePath,
                $vaultPath,
                $frontmatter->relationships,
            );

            $pageKey = 'page:' . $pageId;

            if (isset($pages[$pageKey])) {
                $duplicates[$pageKey] ??= [
                    'pageId' => $pageId,
                    'paths' => [$pages[$pageKey]->vaultPath],
                ];
                $duplicates[$pageKey]['paths'][] = $vaultPath;

                continue;
            }

            $pages[$pageKey] = $page;
        }

        $duplicatePages = array_map(
            static fn(array $duplicate): DuplicateConfluencePage => new DuplicateConfluencePage(
                $duplicate['pageId'],
                $duplicate['paths'],
            ),
            array_values($duplicates),
        );

        return new ConfluencePageIndexResult(
            new ConfluencePageIndex(array_values($pages), $duplicatePages),
            $discovered,
            $indexed,
            $skipped,
            $invalidNotes,
        );
    }

    private function isWithin(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }

    private function readFrontmatter(string $path): string|false
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            $firstLine = fgets($stream);

            if ($firstLine === false) {
                return '';
            }

            if (rtrim($firstLine, "\r\n") !== '---') {
                return $firstLine;
            }

            $frontmatter = $firstLine;

            while (($line = fgets($stream)) !== false) {
                $frontmatter .= $line;

                if (rtrim($line, "\r\n") === '---') {
                    break;
                }
            }

            return $frontmatter;
        } finally {
            fclose($stream);
        }
    }
}
