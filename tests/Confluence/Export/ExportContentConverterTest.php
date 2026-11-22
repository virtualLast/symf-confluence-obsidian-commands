<?php

declare(strict_types=1);

namespace App\Tests\Confluence\Export;

use App\Confluence\Export\ConfluencePageUrl;
use App\Confluence\Export\ExportContentConverter;
use App\Confluence\Export\ExportLinkResolver;
use App\Confluence\Export\ExportNoteReader;
use App\Obsidian\Frontmatter\FrontmatterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExportContentConverterTest extends TestCase
{
    private string $vault;
    private ExportNoteReader $reader;
    private ExportContentConverter $converter;

    protected function setUp(): void
    {
        $this->vault = sys_get_temp_dir() . '/confluence-export-' . bin2hex(random_bytes(8));
        mkdir($this->vault);
        $this->reader = new ExportNoteReader(new FrontmatterParser(), $this->vault);
        $this->converter = new ExportContentConverter(new ExportLinkResolver($this->reader, new ConfluencePageUrl('https://example.atlassian.net')));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->vault);
    }

    public function testItConvertsSupportedFormattingAndKeepsCodeLiteral(): void
    {
        $this->note('source', <<<'MD'
# Heading

A **bold** and *italic* [web link](https://example.com).

- One
- Two

1. First
2. Second

| Name | Value |
| --- | --- |
| a | b |

`[[missing]] ![[image.png]] <b>`

```php
[[missing]] ![[image.png]] <b>
```
MD);
        $result = $this->converter->convert($this->reader->read('source.md'));
        foreach (['<h1>Heading</h1>', '<strong>bold</strong>', '<em>italic</em>', '<ul>', '<ol>', '<table>', '<code>[[missing]] ![[image.png]] &lt;b&gt;</code>', '<pre><code class="language-php">'] as $expected) {
            self::assertStringContainsString($expected, $result->storage);
        }
        self::assertSame([], $result->warnings);
        self::assertStringNotContainsString('source: confluence', $result->storage);
    }

    public function testItSkipsAllImageFormsAndWikiEmbedsButPreservesSurroundingText(): void
    {
        $this->note('source', "Before ![[missing.png]] after ![alt](missing.png) ![ref][image]\n\n![[another-note]]\n\n[image]: https://example.com/image.png");
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertCount(4, $result->warnings);
        self::assertStringContainsString('Before  after', $result->storage);
        self::assertStringNotContainsString('<img', $result->storage);
        self::assertStringNotContainsString('missing.png', $result->storage);
    }

    public function testItIgnoresUnsupportedFormattingInsideOmittedImages(): void
    {
        $this->note('source', '![==important==](missing.png) ![~~obsolete~~](other.png)');
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertCount(2, $result->warnings);
        self::assertStringNotContainsString('important', $result->storage);
        self::assertStringNotContainsString('obsolete', $result->storage);
    }

    public function testItResolvesWikiAndMarkdownLinksIncludingAliasesAndFragments(): void
    {
        mkdir($this->vault . '/Folder');
        $this->note('Folder/target', 'Target', '456');
        $this->note('source', '[[target#My Heading|Alias]] [[Folder/target]] [relative](Folder/target.md) [[#Local Heading]]');
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertStringContainsString('pageId=456#My-Heading">Alias</a>', $result->storage);
        self::assertStringContainsString('pageId=456">Folder/target</a>', $result->storage);
        self::assertStringContainsString('pageId=456">relative</a>', $result->storage);
        self::assertStringContainsString('pageId=123#Local-Heading', $result->storage);
    }

    public function testItRejectsAmbiguousBasenamesEvenWithOneInTheSourceDirectory(): void
    {
        mkdir($this->vault . '/Other');
        $this->note('target', 'First', '1');
        $this->note('Other/target', 'Second', '2');
        $this->note('source', '[[target]]');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous');
        $this->converter->convert($this->reader->read('source.md'));
    }

    #[DataProvider('unsupportedBodies')]
    public function testItRejectsUnsupportedContentAndUnresolvedLinks(string $body): void
    {
        $this->note('source', $body);
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->convert($this->reader->read('source.md'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedBodies(): iterable
    {
        yield 'missing wiki link' => ['[[missing]]'];
        yield 'missing markdown link' => ['[missing](missing.md)'];
        yield 'html' => ['<div>raw</div>'];
        yield 'callout' => ["> [!note]\n> Content"];
        yield 'footnote' => ['Text[^1]'];
        yield 'comment' => ['%%hidden%%'];
        yield 'math' => ['$$x$$'];
        yield 'highlight' => ['==important=='];
        yield 'nested highlight' => ['==**important**=='];
        yield 'nested strikethrough' => ['~~**obsolete**~~'];
        yield 'task list' => ['- [ ] Task'];
        yield 'unsafe url' => ['[bad](javascript:alert%281%29)'];
        yield 'block link' => ['[[#^block]]'];
    }

    public function testItRejectsNativeNotesAndPathsOutsideVault(): void
    {
        file_put_contents($this->vault . '/native.md', '# Native note');
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read('native.md');
    }

    public function testItRejectsSymlinkEscapes(): void
    {
        symlink(__FILE__, $this->vault . '/outside.md');
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read('outside.md');
    }

    public function testItAllowsMissingImportedVersionAndVaultOverrideWithoutWriting(): void
    {
        $this->note('source', '# Heading');
        $before = file_get_contents($this->vault . '/source.md');
        $reader = new ExportNoteReader(new FrontmatterParser(), '/does-not-exist');
        $note = $reader->read('source.md', $this->vault);
        self::assertSame('123', $note->pageId);
        self::assertSame('# Heading', $note->markdown);
        self::assertSame($before, file_get_contents($note->path));
    }

    private function note(string $name, string $body, string $id = '123'): void
    {
        file_put_contents($this->vault . '/' . $name . '.md', "---\nsource: confluence\nconfluence:\n  pageId: '$id'\n---\n" . $body);
    }
}
