<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class ObsidianWriter
{
    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        private string $vaultPath,
        private SluggerInterface $slugger,
        private Filesystem $filesystem,
    ) {}

    public function write(
        ImportedPage $page,
        string $destination = '',
    ): string {
        $filePath = $this->buildFilePath(
            $page,
            $destination,
        );

        $this->filesystem->dumpFile(
            $filePath,
            $page->content,
        );

        return $filePath;
    }

    private function buildFilePath(
        ImportedPage $page,
        string $destination = '',
    ): string {
        $vaultPath = $this->prepareVaultPath();
        $destinationPath = $this->prepareDestinationPath(
            $vaultPath,
            $destination,
        );

        return sprintf(
            '%s%s%s.md',
            $destinationPath,
            DIRECTORY_SEPARATOR,
            $this->makeFilename($page->title),
        );
    }

    private function prepareVaultPath(): string
    {
        if ($this->vaultPath === '' || !$this->filesystem->isAbsolutePath($this->vaultPath)) {
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

    private function prepareDestinationPath(
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

    private function makeFilename(string $title): string
    {
        $filename = $this->slugger
            ->slug($title)
            ->lower()
            ->toString();

        if ($filename !== '') {
            return $filename;
        }

        return sprintf(
            'untitled-%s',
            date('y-m-d-H:i:s'),
        );
    }
}
