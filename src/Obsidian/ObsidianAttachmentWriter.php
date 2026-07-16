<?php

namespace App\Obsidian;

use App\Model\ImportedAttachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

class ObsidianAttachmentWriter
{

    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        private string $vaultPath,
        private Filesystem $filesystem,
    ) {
    }

    public function write(
        ImportedAttachment $imported,
        string $destination = '',
    ): string {
        $filePath = $this->buildFilePath(
            $imported,
            $destination,
        );

        $this->filesystem->dumpFile(
            $filePath,
            $imported->content,
        );

        return $filePath;
    }
}
