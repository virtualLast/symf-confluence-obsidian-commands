<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceContentConverter;
use App\Tests\Traits\FixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfluenceContentConverterTest extends TestCase
{
    use FixtureTrait;

    private ConfluenceContentConverter $converter;

    protected function setUp(): void
    {
        $this->converter = ConfluenceContentConverterFactory::create();
    }

    /**
     * What: Basic Confluence HTML is converted into the expected Markdown.
     * How: The basic HTML fixture is converted and compared with its normalised Markdown fixture.
     * Why: Common headings and paragraphs must survive the central import conversion path.
     */
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

    /**
     * What: Mixed inline and block formatting converts correctly in one document.
     * How: A representative mixed-format fixture is compared with its expected Markdown output.
     * Why: Real Confluence pages combine formats whose conversion rules can interact.
     */
    #[Test]
    public function itConvertsMixedFormatting(): void
    {
        $html = $this->fixture('mixed.html');
        $expected = $this->fixture('mixed.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    /**
     * What: HTML tables become structurally equivalent Markdown tables.
     * How: A table fixture is converted and compared with the expected normalised Markdown.
     * Why: Tabular Confluence content must remain readable and organised after import.
     */
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

    /**
     * What: Nested list hierarchy is retained during conversion.
     * How: Nested-list HTML and Markdown fixtures are converted, normalised, and compared.
     * Why: Losing indentation would change the meaning and grouping of imported list items.
     */
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

    /**
     * What: External HTML anchors are emitted as Markdown links.
     * How: An external-link fixture is converted and matched against the expected Markdown.
     * Why: Imported documentation must retain navigable references to external resources.
     */
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

    /**
     * What: HTML code blocks are converted to Markdown fenced code blocks.
     * How: The code-block fixture is converted and compared with its expected Markdown fixture.
     * Why: Source examples must retain their block structure and formatting after import.
     */
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

    /**
     * What: Inline code remains inline code in the generated Markdown.
     * How: An inline-code fixture is converted and compared with the expected output.
     * Why: Commands and identifiers embedded in prose must remain visually distinct.
     */
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

    /**
     * What: HTML images are represented with the expected Markdown image syntax.
     * How: The image fixture is converted and compared with its normalised Markdown counterpart.
     * Why: Visual references and their metadata must not disappear from imported pages.
     */
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

    /**
     * What: HTML blockquotes are converted to Markdown blockquotes.
     * How: A blockquote fixture is converted and compared with the expected Markdown.
     * Why: Quoted content must preserve its semantic distinction from surrounding prose.
     */
    #[Test]
    public function itConvertsBlockquotes(): void
    {
        $html = $this->fixture('blockquote.html');
        $expected = $this->fixture('blockquote.md');

        $actual = $this->converter->toMarkdown($html);

        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }

    /**
     * What: An HTML horizontal rule becomes its Markdown equivalent.
     * How: The rule fixture is converted and compared with the expected normalised output.
     * Why: Section boundaries should remain visible in imported documentation.
     */
    #[Test]
    public function itConvertsHorizontalRule(): void
    {
        $html = $this->fixture('hr.html');
        $expected = $this->fixture('hr.md');

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

    /**
     * What: A standalone URL and a following named link remain separate Markdown blocks.
     * How: Adjacent link shapes are converted and their exact normalised output is asserted.
     * Why: Without separation, unrelated links can collapse onto one line and become ambiguous.
     */
    #[Test]
    public function itSeparatesStandaloneLinksFromFollowingLinks(): void
    {
        // Arrange
        $html = <<<'HTML'
<div class="innerCell">
    <a class="external-link" href="https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641">https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641</a>
    <p><a href="https://example.com/questions">Questions for Tesco</a></p>
</div>
HTML;

        $expected = <<<'MARKDOWN'
<https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641>

[Questions for Tesco](https://example.com/questions)
MARKDOWN;

        // Act
        $actual = $this->converter->toMarkdown($html);

        // Assert
        self::assertSame(
            $this->normaliseMarkdown($expected),
            $this->normaliseMarkdown($actual),
        );
    }
}
