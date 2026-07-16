<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ObsidianPageWriter extends ObsidianWriter
{
    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        string $vaultPath,
        Filesystem $filesystem,
        private readonly SluggerInterface $slugger,
    ) {
        parent::__construct(
            $filesystem,
            $vaultPath,
        );
    }

    public function write(
        ImportedPage $imported,
        string $destination = '',
    ): string {
        return $this->writeFile(
            $this->buildFilePath($imported, $destination),
            $imported->content,
        );
    }

    private function buildFilePath(
        ImportedPage $imported,
        string $destination = '',
    ): string {
        $vaultPath = $this->prepareVaultPath();
        $destinationPath = $this->prepareDestinationPath(
            $vaultPath,
            $destination,
        );

        return $this->prepareFilePath(
            $destinationPath,
            $this->makeFilename($imported->title) . '.md',
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
