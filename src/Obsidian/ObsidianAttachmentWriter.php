<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedAttachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class ObsidianAttachmentWriter extends ObsidianWriter
{
    public function __construct(
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        string $vaultPath,
        Filesystem $filesystem,
    ) {
        parent::__construct(
            $filesystem,
            $vaultPath,
        );
    }

    public function write(
        ImportedAttachment $imported,
        string $destination = '',
    ): string {
        return $this->writeFile(
            $this->buildFilePath($imported, $destination),
            $imported->content,
        );
    }

    private function buildFilePath(
        ImportedAttachment $imported,
        string $destination = '',
    ): string {
        $vaultPath = $this->prepareVaultPath();
        $attachmentPath = $this->prepareDestinationPath(
            $vaultPath,
            'Attachments',
        );
        $destinationPath = $this->prepareDestinationPath(
            $attachmentPath,
            $destination,
        );

        return $this->prepareFilePath(
            $destinationPath,
            $imported->filename,
        );
    }
}
