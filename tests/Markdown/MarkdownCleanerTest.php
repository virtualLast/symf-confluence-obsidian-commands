<?php

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
