<?php

declare(strict_types=1);

namespace App\Obsidian;

interface ObsidianImageLinkBuilderInterface
{
    public function build(string $vaultPath): string;
}
