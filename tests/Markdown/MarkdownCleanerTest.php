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
