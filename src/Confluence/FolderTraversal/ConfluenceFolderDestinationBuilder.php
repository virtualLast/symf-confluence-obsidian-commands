<?php

declare(strict_types=1);

namespace App\Confluence\FolderTraversal;

use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class ConfluenceFolderDestinationBuilder
{
    public function __construct(private SluggerInterface $slugger) {}

    public function append(
        string $parentDestination,
        string $folderTitle,
        string $folderId,
    ): string {
        $segment = $this->slugger->slug($folderTitle)->toString();

        if ($segment === '') {
            $segment = 'untitled-folder-' . $folderId;
        }

        if ($parentDestination === '') {
            return $segment;
        }

        return rtrim(str_replace('\\', '/', $parentDestination), '/') . '/' . $segment;
    }

    public function appendPage(
        string $parentDestination,
        string $pageTitle,
        string $pageId,
    ): string {
        $segment = $this->slugger->slug($pageTitle)->toString();

        if ($segment === '') {
            $segment = 'untitled-page-' . $pageId;
        }

        if ($parentDestination === '') {
            return $segment;
        }

        return rtrim(str_replace('\\', '/', $parentDestination), '/') . '/' . $segment;
    }

    public function importDestination(
        string $importRoot,
        string $folderDestination,
    ): string {
        $root = rtrim(str_replace('\\', '/', $importRoot), '/');

        if ($root === '') {
            return $folderDestination;
        }

        return $root . '/' . $folderDestination;
    }
}
