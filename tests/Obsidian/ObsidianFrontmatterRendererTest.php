<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Model\ImportedLinkedPage;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianFrontmatterRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ObsidianFrontmatterRendererTest extends TestCase
{
    public function testItRendersIdentityAndRelationshipsAsYamlFrontmatter(): void
    {
        $page = new ImportedPage(
            id: 664600597,
            title: 'SSO Overview',
            content: '# SSO Overview',
            spaceKey: 'SD',
            version: 17,
            linkedPages: [
                new ImportedLinkedPage(
                    665747466,
                    'Lightfoot SSO Implementation',
                    'https://lightfoot.atlassian.net/wiki/spaces/SD/pages/665747466',
                ),
            ],
        );

        $rendered = new ObsidianFrontmatterRenderer()->render($page);
        [, $yaml, $content] = explode('---', $rendered, 3);
        $metadata = Yaml::parse($yaml);

        self::assertSame('confluence', $metadata['source']);
        self::assertSame('664600597', $metadata['confluence']['pageId']);
        self::assertSame('SSO Overview', $metadata['confluence']['title']);
        self::assertSame('SD', $metadata['confluence']['spaceKey']);
        self::assertSame(17, $metadata['confluence']['version']);
        self::assertSame(
            [
                [
                    'pageId' => '665747466',
                    'title' => 'Lightfoot SSO Implementation',
                    'url' => 'https://lightfoot.atlassian.net/wiki/spaces/SD/pages/665747466',
                ],
            ],
            $metadata['relationships']['linkedPages'],
        );
        self::assertSame("\n\n# SSO Overview", $content);
    }

    public function testItRendersAnEmptyRelationshipListAsASequence(): void
    {
        $rendered = new ObsidianFrontmatterRenderer()->render(
            new ImportedPage(123, 'Page', 'Content', spaceKey: 'SD'),
        );

        self::assertStringContainsString("  linkedPages: []\n", $rendered);
    }
}
