<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class ObsidianPathBuilder
{
    private const string ATTACHMENT_NAMESPACE = 'Attachments/Confluence';

    public function __construct(private SluggerInterface $slugger) {}

    public function pageFilename(ImportedPage $page): string
    {
        return $this->pageSlug($page) . '.md';
    }

    public function attachmentVaultPath(
        ImportedPage $page,
        ImportedAttachment $attachment,
    ): string {
        if (
            $attachment->filename === ''
            || $attachment->filename === '.'
            || $attachment->filename === '..'
            || str_contains($attachment->filename, '/')
            || str_contains($attachment->filename, '\\')
            || str_contains($attachment->filename, "\0")
        ) {
            throw new \InvalidArgumentException(
                'The filename must be a non-empty filename without path segments.',
            );
        }

        return sprintf(
            '%s/%s/%s',
            self::ATTACHMENT_NAMESPACE,
            $this->pageSlug($page),
            $attachment->filename,
        );
    }

    private function pageSlug(ImportedPage $page): string
    {
        $slug = $this->slugger
            ->slug($page->title)
            ->lower()
            ->toString();

        if ($slug !== '') {
            return $slug;
        }

        return sprintf('untitled-%s', date('y-m-d-H:i:s'));
    }
}
