<?php

declare(strict_types=1);

namespace App\Obsidian\Index;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ConfluenceNotesDirectoryResolver
{
    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        private string $vaultPath,
        private Filesystem $filesystem,
    ) {}

    public function resolve(string $directory): ResolvedNotesDirectory
    {
        if ($this->vaultPath === '' || !$this->filesystem->isAbsolutePath($this->vaultPath)) {
            throw new \InvalidArgumentException('OBSIDIAN_VAULT must be a non-empty absolute path.');
        }

        $vaultPath = realpath($this->vaultPath);

        if ($vaultPath === false || !is_dir($vaultPath)) {
            throw new \InvalidArgumentException('OBSIDIAN_VAULT must resolve to an existing directory.');
        }

        $normalised = str_replace('\\', '/', $directory);

        if (
            $normalised === ''
            || str_starts_with($normalised, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalised) === 1
        ) {
            throw new \InvalidArgumentException('The notes directory must be a non-empty path relative to OBSIDIAN_VAULT.');
        }

        $normalised = rtrim($normalised, '/');
        $segments = explode('/', $normalised);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new \InvalidArgumentException('The notes directory contains an unsafe path segment.');
            }
        }

        $absolutePath = realpath($vaultPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));

        if ($absolutePath === false || !is_dir($absolutePath)) {
            throw new \InvalidArgumentException(sprintf(
                'The notes directory "%s" does not exist.',
                $normalised,
            ));
        }

        if (!$this->isWithin($absolutePath, $vaultPath)) {
            throw new \InvalidArgumentException('The notes directory must remain within OBSIDIAN_VAULT.');
        }

        if (!is_readable($absolutePath)) {
            throw new \InvalidArgumentException(sprintf(
                'The notes directory "%s" is not readable.',
                $normalised,
            ));
        }

        return new ResolvedNotesDirectory(
            $vaultPath,
            $absolutePath,
            $normalised,
        );
    }

    private function isWithin(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }
}
