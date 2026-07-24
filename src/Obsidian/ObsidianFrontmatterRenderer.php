<?php

declare(strict_types=1);

namespace App\Obsidian;

use App\Model\ImportedLinkedPage;
use App\Model\ImportedPage;
use Symfony\Component\Yaml\Yaml;

final readonly class ObsidianFrontmatterRenderer
{
    public function render(ImportedPage $page): string
    {
        $metadata = [
            'source' => 'confluence',
            'confluence' => [
                'pageId' => $page->id,
                'title' => $page->title,
                'spaceId' => $page->spaceId,
                'spaceKey' => $page->spaceKey,
                'version' => $page->version,
            ],
            'relationships' => [
                'linkedPages' => array_map(
                    static fn(ImportedLinkedPage $linkedPage): array => [
                        'pageId' => $linkedPage->id,
                        'title' => $linkedPage->title,
                        'url' => $linkedPage->url,
                    ],
                    $page->linkedPages,
                ),
            ],
        ];

        return "---\n"
            . Yaml::dump($metadata, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
            . "---\n\n"
            . $page->content;
    }
}
