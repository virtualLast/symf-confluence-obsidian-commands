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

    public function write(ImportedPage $page): string
    {
        $filePath = $this->buildFilePath($page);
        $this->filesystem->dumpFile($filePath, $page->content);

        return $filePath;
    }

    private function buildFilePath(ImportedPage $page): string
    {
        $filename = $this->makeFilename($page->title);

        return sprintf(
            '%s%s%s.md',
            rtrim($this->vaultPath, DIRECTORY_SEPARATOR),
            DIRECTORY_SEPARATOR,
            $filename,
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
