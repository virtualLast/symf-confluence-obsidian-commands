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

    public function testItPlansImagesAndSkipsNonImageEmbeds(): void
    {
        $this->image('image.png');
        $this->note('source', "Before ![[image.png|300x200]] after ![alt](image.png) ![ref][image]\n\n![[another-note]]\n\n[image]: https://example.com/image.png");
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertCount(1, $result->warnings);
        self::assertCount(1, $result->images);
        self::assertSame('image.png', $result->images[0]->vaultPath);
        self::assertStringContainsString('ac:width="300" ac:height="200"', $result->storage);
        self::assertSame(2, substr_count($result->storage, '<ri:attachment'));
        self::assertStringContainsString('<ri:url ri:value="https://example.com/image.png"', $result->storage);
    }

    public function testImageNamesStayStableForChangedBytesAndDifferAcrossPaths(): void
    {
        mkdir($this->vault . '/Other');
        $this->image('image.png');
        $this->image('Other/image.png');
        $this->note('source', '![one](image.png) ![two](Other/image.png)');
        $before = $this->converter->convert($this->reader->read('source.md'));
        file_put_contents($this->vault . '/image.png', 'changed', FILE_APPEND);
        $after = $this->converter->convert($this->reader->read('source.md'));
        self::assertNotSame($before->images[0]->filename, $before->images[1]->filename);
        self::assertSame($before->images[0]->filename, $after->images[0]->filename);
        self::assertNotSame($before->images[0]->content, $after->images[0]->content);
    }

    public function testImageAltFormattingDoesNotBlockPublication(): void
    {
        $this->image('image.png');
        $this->note('source', '![==important==](image.png) ![~~obsolete~~](image.png)');
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertCount(1, $result->images);
        self::assertSame(2, substr_count($result->storage, '<ac:image'));
    }

    public function testItExportsNestedCalloutsAndLeavesOrdinaryQuotesAndCodeAlone(): void
    {
        $this->note('source', <<<'MD'
> [!note]- Outer & title
> First **paragraph**.
>
> > [!info]+ Inner
> > - Child
>
> Last paragraph.

> Ordinary quote

```markdown
> [!note]- Literal
```
MD);
        $result = $this->converter->convert($this->reader->read('source.md'));
        self::assertSame(2, substr_count($result->storage, 'ac:name="expand"'));
        self::assertStringContainsString('Outer &amp; title</ac:parameter>', $result->storage);
        self::assertStringContainsString('<strong>paragraph</strong>', $result->storage);
        self::assertStringContainsString('<li>Child</li>', $result->storage);
        self::assertStringContainsString('Last paragraph.', $result->storage);
        self::assertStringContainsString('<blockquote>', $result->storage);
        self::assertStringContainsString('&gt; [!note]- Literal', $result->storage);
    }

    public function testImageSymlinksCannotEscapeTheVault(): void
    {
        symlink(__FILE__, $this->vault . '/image.png');
        $this->note('source', '![[image.png]]');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inside the vault');
        $this->converter->convert($this->reader->read('source.md'));
    }

    public function testAmbiguousImageBasenamesFail(): void
    {
        mkdir($this->vault . '/Other');
        $this->image('image.png');
        $this->image('Other/image.png');
        $this->note('source', '![[image.png]]');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous image');
        $this->converter->convert($this->reader->read('source.md'));
    }

    public function testImagesInsideCalloutsRemainInsideTheExpandBody(): void
    {
        mkdir($this->vault . '/Folder');
        $this->image('image.png');
        $this->note('Folder/source', "> [!note]- Diagram\n> ![alt](../image.png)\n");
        $result = $this->converter->convert($this->reader->read('Folder/source.md'));
        self::assertCount(1, $result->images);
        self::assertStringContainsString('<ac:rich-text-body><p><ac:image ac:alt="alt">', $result->storage);
        self::assertStringContainsString('</ac:image></p>', $result->storage);
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
        yield 'missing image' => ['![[missing.png]]'];
        yield 'unsafe image URL' => ['![bad](file:///tmp/image.png)'];
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

    private function image(string $path): void
    {
        file_put_contents($this->vault . '/' . $path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true));
    }

    private function note(string $name, string $body, string $id = '123'): void
    {
        file_put_contents($this->vault . '/' . $name . '.md', "---\nsource: confluence\nconfluence:\n  pageId: '$id'\n---\n" . $body);
    }
}
