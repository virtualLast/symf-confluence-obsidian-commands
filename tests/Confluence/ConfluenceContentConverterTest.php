<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceContentConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfluenceContentConverterTest extends TestCase
{
    use ConfluenceFixtureTrait;

    private ConfluenceContentConverter $converter;

    protected function setUp(): void
    {
        $this->converter = ConfluenceContentConverterFactory::create();
    }

    #[Test]
    public function itConvertsBasicHtmlToMarkdown(): void
    {
        $html = $this->fixture('basic-content.html');
        $expected = rtrim($this->fixture('basic-content.md'), "\r\n");

        $actual = $this->converter->toMarkdown($html);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function itConvertsHtmlTablesToMarkdownTables(): void
    {
        $html = $this->fixture('table.html');
        $expected = $this->fixture('table.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(trim($expected), trim($actual));
    }

    #[Test]
    public function itPreservesNestedLists(): void
    {
        $html = $this->fixture('nested-list.html');
        $expected = $this->fixture('nested-list.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function itConvertsCodeBlocks(): void
    {
        $html = $this->fixture('code-block.html');
        $expected = $this->fixture('code-block.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function itConvertsExternalLinks(): void
    {
        $html = $this->fixture('external-link.html');
        $expected = $this->fixture('external-link.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function itConvertsRenderedConfluencePage(): void
    {
        $html = $this->fixture('realistic-page.html');
        $expected = $this->fixture('realistic-page.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame($expected, $actual);
    }
}
