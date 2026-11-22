<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use Symfony\Component\Filesystem\Path;

final readonly class ExportLinkResolver
{
    public function __construct(private ExportNoteReader $reader, private ConfluencePageUrl $pageUrl) {}

    /** Resolve a local note reference, rejecting missing or ambiguous matches. */
    public function resolve(string $target, ExportNote $source, bool $wiki = false): string
    {
        $parts = explode('#', $target, 2);
        $path = rawurldecode($parts[0]);
        $fragment = isset($parts[1]) ? rawurldecode($parts[1]) : null;
        if ($fragment !== null && str_starts_with($fragment, '^')) {
            throw new \InvalidArgumentException('Obsidian block links are not supported: ' . $target);
        }
        if ($path === '') {
            return $this->pageUrl->forPage($source->pageId) . ($fragment === null ? '' : '#' . rawurlencode(str_replace(' ', '-', $fragment)));
        }
        $path = str_replace('\\', '/', $path);
        $path = str_ends_with(strtolower($path), '.md') ? $path : $path . '.md';
        $matches = [];
        if ($wiki && !str_contains($path, '/')) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source->vault, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() === $path) {
                    $matches[] = $file->getPathname();
                }
            }
        } else {
            $base = $wiki && !str_starts_with($path, '.') ? $source->vault : dirname($source->path);
            $candidate = Path::canonicalize($base . '/' . ltrim($path, '/'));
            if (is_file($candidate)) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unresolved or ambiguous note link: %s (%d matches).', $target, count($matches)));
        }
        $note = $this->reader->read($matches[0], $source->vault);

        return $this->pageUrl->forPage($note->pageId) . ($fragment === null ? '' : '#' . rawurlencode(str_replace(' ', '-', $fragment)));
    }
}
