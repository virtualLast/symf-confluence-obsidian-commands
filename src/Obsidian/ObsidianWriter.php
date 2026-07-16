<?php

declare(strict_types=1);

namespace App\Obsidian;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

abstract class ObsidianWriter
{
    public function __construct(
        protected readonly Filesystem $filesystem,
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        protected readonly string $vaultPath,
    ) {}

    final protected function writeFile(
        string $filePath,
        string $content,
    ): string {
        $this->filesystem->dumpFile(
            $filePath,
            $content,
        );

        return $filePath;
    }

    final protected function prepareFilePath(
        string $destinationPath,
        string $filename,
    ): string {
        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")
        ) {
            throw new \InvalidArgumentException(
                'The filename must be a non-empty filename without path segments.',
            );
        }

        return $destinationPath . DIRECTORY_SEPARATOR . $filename;
    }

    final protected function prepareVaultPath(): string
    {
        if (
            $this->vaultPath === ''
            || !$this->filesystem->isAbsolutePath($this->vaultPath)
        ) {
            throw new \InvalidArgumentException(
                'OBSIDIAN_VAULT must be a non-empty absolute path.',
            );
        }

        $this->filesystem->mkdir($this->vaultPath);

        $vaultPath = realpath($this->vaultPath);

        if ($vaultPath === false || !is_dir($vaultPath)) {
            throw new \InvalidArgumentException(
                'OBSIDIAN_VAULT must resolve to a directory.',
            );
        }

        return $vaultPath;
    }

    protected function prepareDestinationPath(
        string $vaultPath,
        string $destination,
    ): string {
        if ($destination === '') {
            return $vaultPath;
        }

        $normalisedDestination = str_replace('\\', '/', $destination);

        if (
            str_starts_with($normalisedDestination, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalisedDestination) === 1
        ) {
            throw new \InvalidArgumentException(
                'The destination must be relative to OBSIDIAN_VAULT.',
            );
        }

        $segments = explode('/', rtrim($normalisedDestination, '/'));
        $destinationPath = $vaultPath;

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException(
                    'The destination contains an unsafe path segment.',
                );
            }

            $destinationPath .= DIRECTORY_SEPARATOR . $segment;

            if (is_link($destinationPath)) {
                throw new \InvalidArgumentException(
                    'The destination must not contain symbolic links.',
                );
            }
        }

        $this->filesystem->mkdir($destinationPath);

        $resolvedDestination = realpath($destinationPath);

        if (
            $resolvedDestination === false
            || !$this->isWithinVault($resolvedDestination, $vaultPath)
        ) {
            throw new \InvalidArgumentException(
                'The destination must remain within OBSIDIAN_VAULT.',
            );
        }

        return $resolvedDestination;
    }

    private function isWithinVault(string $path, string $vaultPath): bool
    {
        return $path === $vaultPath
            || str_starts_with(
                $path,
                $vaultPath . DIRECTORY_SEPARATOR,
            );
    }
}
