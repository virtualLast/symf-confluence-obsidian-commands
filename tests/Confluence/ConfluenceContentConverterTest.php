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
        $expected = $this->fixture('basic-content.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itConvertsHtmlTablesToMarkdownTables(): void
    {
        $html = $this->fixture('table.html');
        $expected = $this->fixture('table.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itPreservesNestedLists(): void
    {
        $html = $this->fixture('nested-list.html');
        $expected = $this->fixture('nested-list.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itConvertsExternalLinks(): void
    {
        $html = $this->fixture('external-link.html');
        $expected = $this->fixture('external-link.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itConvertsCodeBlocks(): void
    {
        $html = $this->fixture('code-block.html');
        $expected = $this->fixture('code-block.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itPreservesInlineCode(): void
    {
        $html = $this->fixture('inline-code.html');
        $expected = $this->fixture('inline-code.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    #[Test]
    public function itConvertsImages(): void
    {
        $html = $this->fixture('image.html');
        $expected = $this->fixture('image.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    private function normaliseMarkdown(string $content): string
    {
        $content = str_replace("\r\n", "\n", $content);

        $content = preg_replace(
            '/[ \t]+$/m',
            '',
            $content,
        ) ?? $content;

        return trim($content);
    }
}
