<?php

declare(strict_types=1);

namespace App\Tests\Obsidian\Link;

use App\Obsidian\Frontmatter\ConfluenceRelationship;
use App\Obsidian\Index\ConfluencePageIndex;
use App\Obsidian\Index\IndexedConfluencePage;
use App\Obsidian\Link\ConfluenceLinkResolver;
use PHPUnit\Framework\TestCase;

final class ConfluenceLinkResolverTest extends TestCase
{
    private const string URL = 'https://example.atlassian.net/wiki/spaces/SD/pages/456/Target+(Page)';

    public function testItResolvesEveryMatchingLinkAndPreservesFrontmatterAndLabels(): void
    {
        $frontmatter = $this->frontmatter('123');
        $content = $frontmatter
            . "Read [Existing label](<" . self::URL . "> \"title\").\n"
            . "Again [Target](" . self::URL . ").\n"
            . "Target is ordinary prose.\n"
            . "[External](https://example.com).";

        $result = $this->resolver()->resolve($this->source(), $this->index(), $content);

        self::assertSame(1, $result->relationshipsChecked);
        self::assertSame(2, $result->linksResolved);
        self::assertSame([], $result->unresolved);
        self::assertTrue($result->changed);
        self::assertSame($frontmatter, substr($result->content, 0, strlen($frontmatter)));
        self::assertStringContainsString(
            '[[Inbox/Confluence/Nested/target|Existing label]]',
            $result->content,
        );
        self::assertStringContainsString(
            '[[Inbox/Confluence/Nested/target|Target]]',
            $result->content,
        );
        self::assertStringContainsString('Target is ordinary prose.', $result->content);
        self::assertStringContainsString('[External](https://example.com)', $result->content);
    }

    public function testItLeavesCodeImagesAndReferenceLinksUnchanged(): void
    {
        $markdown = sprintf(
            "Inline `%s`.\n\n```markdown\n[Code](%s)\n```\n\n![Image](%s)\n[Reference][target]\n",
            '[Inline](' . self::URL . ')',
            self::URL,
            self::URL,
        );
        $content = $this->frontmatter('123') . $markdown;

        $result = $this->resolver()->resolve($this->source(), $this->index(), $content);

        self::assertFalse($result->changed);
        self::assertSame(0, $result->linksResolved);
        self::assertSame($content, $result->content);
    }

    public function testItLeavesAnUnresolvedRelationshipUnchangedAndReportsIt(): void
    {
        $source = $this->source(new ConfluenceRelationship('999', 'Missing', self::URL));
        $content = $this->frontmatter('123') . '[Missing](' . self::URL . ')';
        $index = new ConfluencePageIndex([$source], []);

        $result = $this->resolver()->resolve($source, $index, $content);

        self::assertFalse($result->changed);
        self::assertSame(1, $result->relationshipsChecked);
        self::assertCount(1, $result->unresolved);
        self::assertSame('999', $result->unresolved[0]->pageId);
        self::assertSame($content, $result->content);
    }

    public function testItUsesTheRelationshipTitleForAnEmptyLabelAndIsIdempotent(): void
    {
        $content = $this->frontmatter('123') . '[](' . self::URL . ')';
        $first = $this->resolver()->resolve($this->source(), $this->index(), $content);
        $second = $this->resolver()->resolve($this->source(), $this->index(), $first->content);

        self::assertStringEndsWith(
            '[[Inbox/Confluence/Nested/target|Recorded title]]',
            $first->content,
        );
        self::assertTrue($first->changed);
        self::assertFalse($second->changed);
        self::assertSame(0, $second->linksResolved);
        self::assertSame($first->content, $second->content);
    }

    private function resolver(): ConfluenceLinkResolver
    {
        return new ConfluenceLinkResolver();
    }

    private function source(?ConfluenceRelationship $relationship = null): IndexedConfluencePage
    {
        return new IndexedConfluencePage(
            '123',
            '/vault/Inbox/Confluence/source.md',
            'Inbox/Confluence/source.md',
            [$relationship ?? new ConfluenceRelationship('456', 'Recorded title', self::URL)],
        );
    }

    private function index(): ConfluencePageIndex
    {
        $source = $this->source();
        $target = new IndexedConfluencePage(
            '456',
            '/vault/Inbox/Confluence/Nested/target.md',
            'Inbox/Confluence/Nested/target.md',
            [],
        );

        return new ConfluencePageIndex([$source, $target], []);
    }

    private function frontmatter(string $pageId): string
    {
        return sprintf(
            "---\r\nsource: confluence\r\nconfluence:\r\n  pageId: '%s'\r\n---\r\n",
            $pageId,
        );
    }
}
