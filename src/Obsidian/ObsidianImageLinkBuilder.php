<?php

declare(strict_types=1);

namespace App\Obsidian;

final readonly class ObsidianImageLinkBuilder implements ObsidianImageLinkBuilderInterface
{
    public function build(string $vaultPath): string
    {
        return sprintf('![[%s]]', $vaultPath);
    }
}
