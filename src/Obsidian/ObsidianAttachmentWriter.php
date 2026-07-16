<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class ObsidianAttachmentWriter extends ObsidianWriter
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
        ImportedPage $page,
        ImportedAttachment $attachment,
    ): string {
        $vaultPath = $this->pathBuilder->attachmentVaultPath($page, $attachment);
        $segments = explode('/', $vaultPath);
        $filename = array_pop($segments);
        $absoluteDirectory = $this->prepareDestinationPath(
            $this->prepareVaultPath(),
            implode('/', $segments),
        );

        $this->writeFile(
            $this->prepareFilePath($absoluteDirectory, $filename),
            $attachment->content,
        );

        return $vaultPath;
    }
}
