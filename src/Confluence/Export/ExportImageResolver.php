<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;

final class ExportImageResolver
{
    /** Resolve a local image inside the vault; external images need no upload. */
    public function resolve(Image $image, ExportNote $note): ?ExportImage
    {
        $target = $image->getUrl();
        if (preg_match('~^https?://~i', $target) === 1) {
            if (filter_var($target, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('Invalid external image URL: ' . $target);
            }
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1 || str_starts_with($target, '//')) {
            throw new \InvalidArgumentException('Unsupported image URL: ' . $target);
        }
        $wiki = $image->data->get('obsidian', false) === true;
        $path = $wiki ? $target : rawurldecode($target);
        $path = str_replace('\\', '/', $path);
        $matches = [];
        if ($wiki && !str_contains($path, '/')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($note->vault, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() === $path) {
                    $matches[] = $file->getPathname();
                }
            }
        } else {
            $base = ($wiki && !str_starts_with($path, '.')) || str_starts_with($path, '/') ? $note->vault : dirname($note->path);
            $candidate = $base . '/' . ltrim($path, '/');
            if (is_file($candidate)) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('Missing or ambiguous image: ' . $target);
        }
        $absolute = realpath($matches[0]);
        if ($absolute === false || !str_starts_with($absolute, $note->vault . '/') || !is_readable($absolute)) {
            throw new \InvalidArgumentException('Images must be readable files inside the vault: ' . $target);
        }
        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('Unable to read image: ' . $target);
        }
        $mediaType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!is_string($mediaType) || !in_array($mediaType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/avif'], true)) {
            throw new \InvalidArgumentException('Not a supported image file: ' . $target);
        }
        $relative = substr($absolute, strlen($note->vault) + 1);
        $basename = preg_replace('/[^a-zA-Z0-9._-]/', '-', basename($relative)) ?? 'image';
        $filename = 'obsidian-' . substr(hash('sha256', $relative), 0, 20) . '-' . substr($basename, -100);
        $image->data->set('confluence_filename', $filename);

        return new ExportImage($relative, $filename, $mediaType, $content);
    }

    /** Distinguish file/note embeds from image embeds without opening unrelated files. */
    public function isNonImageEmbed(Image $image): bool
    {
        return $image->data->get('obsidian', false) === true
            && preg_match('/\.(?:png|jpe?g|gif|webp|svg|bmp|avif)$/i', $image->getUrl()) !== 1;
    }
}
