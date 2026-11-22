<?php

declare(strict_types=1);

namespace App\Tests\Markdown;

use App\Markdown\MarkdownCleaner;
use App\Tests\Traits\FixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownCleanerTest extends TestCase
{
    use FixtureTrait;

    private MarkdownCleaner $cleaner;

    public function setUp(): void
    {
        $this->cleaner = new MarkdownCleaner();
    }

    public function testItPreservesCodeInsideNestedCalloutsAndResumesProseCleaningAfterwards(): void
    {
        $literal = '&lt;div&gt; - Getting issue details... STATUS';
        $input = "> [!note]- Outer\n> > [!note]- Inner\n> > ```text\n> > $literal\n> > > ```\n> > $literal\n> > ```\n\nAfter &amp; prose\n";
        $expected = str_replace('After &amp; prose', 'After & prose', $input);
        self::assertSame($expected, $this->cleaner->clean($input));
    }

    public function testAnUnclosedQuotedFenceDoesNotProtectFollowingUnquotedProse(): void
    {
        self::assertSame(
            "> ```\n> &lt;code&gt;\nAfter & prose\n",
            $this->cleaner->clean("> ```\n> &lt;code&gt;\nAfter &amp; prose\n"),
        );
    }

    /**
     * What: Markdown whitespace is cleaned without changing fenced code blocks.
     * How: Fixture pairs cover excess blanks and several closed and unclosed fence styles.
     * Why: Imported prose needs consistent spacing while code must retain its original layout.
     */
    #[DataProvider('cleaningFixtureProvider')]
    public function testItCleansMarkdown(
        string $inputFixture,
        string $expectedFixture,
    ): void {

        self::assertSame(
            $this->fixture($expectedFixture),
            $this->cleaner->clean($this->fixture($inputFixture)),
        );
    }

    /**
     * What: Whitespace-only Markdown lines are emptied without changing their line endings.
     * How: CRLF prose blanks and a whitespace-only fenced-code line are cleaned and compared.
     * Why: Generated spacing should be clean while code contents and platform endings remain exact.
     */
    public function testItNormalisesWhitespaceOnlyLinesOutsideCodeFences(): void
    {
        $input = "Before\r\n \t\r\n\r\nAfter\r\n```text\r\n   \r\n```\r\n";
        $expected = "Before\r\n\r\nAfter\r\n```text\r\n   \r\n```\r\n";

        self::assertSame(
            $expected,
            $this->cleaner->clean($input),
        );
    }

    /**
     * What: Confluence macro-rendering diagnostics are removed from imported Markdown.
     * How: An attachments error in prose and the same text in a code fence are cleaned.
     * Why: Failed Confluence macros must not leak internal Java errors into notes.
     */
    public function testItRemovesMacroRenderingErrorsOutsideCodeFences(): void
    {
        $error = 'Error rendering macro \'attachments\' : Cannot invoke "com.atlassian.confluence.core.ContentEntityObject.getId()" because "contentEntityObject" is null';
        $input = "Before\n\n{$error}\n\n\nAfter\n\n```text\n{$error}\n```\n";
        $expected = "Before\n\nAfter\n\n```text\n{$error}\n```\n";

        self::assertSame(
            $expected,
            $this->cleaner->clean($input),
        );
    }

    /**
     * What: Placeholder text emitted by unresolved Jira macros is discarded.
     * How: Standalone and list-item Jira links are cleaned while fenced examples remain exact.
     * Why: "Getting issue details... STATUS" is Confluence UI debris, not authored page content.
     */
    public function testItRemovesJiraMacroPlaceholderTextOutsideCodeFences(): void
    {
        $placeholder = '[LF-13220](https://example.atlassian.net/browse/LF-13220) - Getting issue details... STATUS';
        $input = "{$placeholder}\n- {$placeholder}\n\n```markdown\n{$placeholder}\n```\n";
        $expected = "[LF-13220](https://example.atlassian.net/browse/LF-13220)\n"
            . "- [LF-13220](https://example.atlassian.net/browse/LF-13220)\n\n"
            . "```markdown\n{$placeholder}\n```\n";

        self::assertSame($expected, $this->cleaner->clean($input));
    }

    public function testItRemovesAJiraMacroPlaceholderEmbeddedInProse(): void
    {
        $input = 'Merge [LF-13252](https://example.test/LF-13252)'
            . " - Getting issue details... STATUS  into `develop`.\n"
            . 'Tracked by [LF-13290](https://example.test/LF-13290)'
            . " - Getting issue details... STATUS .\n";
        $expected = "Merge [LF-13252](https://example.test/LF-13252) into `develop`.\n"
            . "Tracked by [LF-13290](https://example.test/LF-13290).\n";

        self::assertSame($expected, $this->cleaner->clean($input));
    }

    /**
     * What: HTML entities left in converted prose are restored to readable characters.
     * How: Text, a table cell, and a URL are cleaned while a fenced sample is unchanged.
     * Why: Imported notes should display "&" and ">" rather than Confluence serialization details.
     */
    public function testItDecodesHtmlEntitiesOutsideCodeFences(): void
    {
        $input = "Stanley Black &amp; Decker\n"
            . "| Route | portal &gt; API |\n"
            . "[Logs](https://example.com/?one=1&amp;two=2)\n\n"
            . "```html\n&amp;\n```\n";
        $expected = "Stanley Black & Decker\n"
            . "| Route | portal > API |\n"
            . "[Logs](https://example.com/?one=1&two=2)\n\n"
            . "```html\n&amp;\n```\n";

        self::assertSame($expected, $this->cleaner->clean($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function cleaningFixtureProvider(): iterable
    {
        yield 'excessive blank lines' => [
            'excessive-blank-lines.input.md',
            'excessive-blank-lines.expected.md',
        ];

        yield 'backtick fenced code block' => [
            'backtick-fence.input.md',
            'backtick-fence.expected.md',
        ];

        yield 'tilde fenced code block' => [
            'tilde-fence.input.md',
            'tilde-fence.expected.md',
        ];

        yield 'long fenced code block' => [
            'long-fence.input.md',
            'long-fence.expected.md',
        ];

        yield 'unclosed fence' => [
            'unclosed-fence.input.md',
            'unclosed-fence.expected.md',
        ];
    }
}
