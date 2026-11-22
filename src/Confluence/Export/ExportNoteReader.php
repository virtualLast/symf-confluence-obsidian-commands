<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use App\Model\ConfluenceId;
use App\Obsidian\Frontmatter\FrontmatterParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExportNoteReader
{
    public function __construct(
        private FrontmatterParser $frontmatterParser,
        #[Autowire(env: 'OBSIDIAN_VAULT')]
        private string $vaultPath,
    ) {}

    /** Read a Markdown note contained in the configured or overridden vault. */
    public function read(string $path, ?string $vaultOverride = null): ExportNote
    {
        $vault = realpath($vaultOverride ?? $this->vaultPath);
        if ($vault === false || !is_dir($vault)) {
            throw new \InvalidArgumentException('The Obsidian vault must be an existing directory.');
        }
        $absolute = realpath(str_starts_with($path, '/') ? $path : $vault . '/' . $path);
        if ($absolute === false || !is_file($absolute) || !str_starts_with($absolute, $vault . '/') || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'md') {
            throw new \InvalidArgumentException('The note must be an existing Markdown file inside the vault.');
        }
        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('Unable to read the note: ' . $absolute);
        }
        $metadata = $this->frontmatterParser->parse($content);
        if ($metadata->pageId === null || $metadata->error !== null) {
            throw new \InvalidArgumentException($metadata->error ?? 'The note must have source: confluence and a valid confluence.pageId.');
        }
        ConfluenceId::toInt($metadata->pageId);
        $body = preg_replace('/\A---\R.*?\R---(?:\R|\z)/s', '', $content, 1);

        return new ExportNote($absolute, $vault, $metadata->pageId, $body ?? '');
    }
}
