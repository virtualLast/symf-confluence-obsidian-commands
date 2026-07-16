<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class ObsidianPageWriter extends ObsidianWriter
{
    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        string $vaultPath,
        Filesystem $filesystem,
        private readonly ObsidianPathBuilder $pathBuilder,
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
            $this->pathBuilder->pageFilename($imported),
        );
    }
}
