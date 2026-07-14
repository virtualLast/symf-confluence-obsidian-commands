<?php

namespace App\Obsidian;

use App\Model\ImportedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ObsidianWriter
{

    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        private string $vaultPath,
        private SluggerInterface $slugger,
        private Filesystem $filesystem
    ) {
    }

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
        $path = rtrim($this->vaultPath, DIRECTORY_SEPARATOR);

        if ($destination !== '') {
            $path .= DIRECTORY_SEPARATOR . trim(
                    $destination,
                    DIRECTORY_SEPARATOR,
                );
        }

        return sprintf(
            '%s%s%s.md',
            $path,
            DIRECTORY_SEPARATOR,
            $this->makeFilename($page->title),
        );
    }

    private function makeFilename(string $title): string
    {
        return $this->slugger
            ->slug($title)
            ->lower()
            ->toString();
    }
}
