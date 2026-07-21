<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceLinkedPageExtractor;
use PHPUnit\Framework\TestCase;

final class ConfluenceLinkedPageExtractorTest extends TestCase
{
    public function testItExtractsAndDeduplicatesConfluencePageLinks(): void
    {
        $html = <<<'HTML'
            <p>
                <a data-linked-resource-type="page" data-linked-resource-id="665747466"
                   data-linked-resource-default-alias="Lightfoot SSO Implementation"
                   href="/wiki/spaces/SD/pages/665747466/Lightfoot+SSO+Implementation">implementation</a>
                <a href="https://lightfoot.atlassian.net/wiki/pages/viewpage.action?pageId=665223176">SSO Issues</a>
                <a href="/wiki/spaces/SD/pages/665747466/duplicate">duplicate</a>
                <a href="https://example.com/pages/123/external">External page</a>
                <a data-linked-resource-type="attachment" data-linked-resource-id="999"
                   href="/wiki/download/attachments/1/file.pdf">File</a>
            </p>
            HTML;

        $links = new ConfluenceLinkedPageExtractor(
            'https://lightfoot.atlassian.net/wiki/',
        )->extract($html);

        self::assertCount(2, $links);
        self::assertSame('665747466', $links[0]->id);
        self::assertSame('Lightfoot SSO Implementation', $links[0]->title);
        self::assertSame(
            'https://lightfoot.atlassian.net/wiki/spaces/SD/pages/665747466/Lightfoot+SSO+Implementation',
            $links[0]->url,
        );
        self::assertSame('665223176', $links[1]->id);
        self::assertSame('SSO Issues', $links[1]->title);
    }

    public function testItReturnsNoRelationshipsForExternalLinks(): void
    {
        $links = new ConfluenceLinkedPageExtractor(
            'https://lightfoot.atlassian.net/wiki/',
        )->extract('<a href="https://symfony.com/doc/current/index.html">Symfony</a>');

        self::assertSame([], $links);
    }
}
