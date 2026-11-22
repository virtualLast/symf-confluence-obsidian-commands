<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\Export\ConfluencePageUrl;
use App\Confluence\Export\ExportContentConverter;
use App\Confluence\Export\ExportLinkResolver;
use App\Confluence\Export\ExportNote;
use App\Confluence\Export\ExportNoteReader;
use App\Obsidian\Frontmatter\FrontmatterParser;
use PHPUnit\Framework\TestCase;

final class ExpandRoundTripTest extends TestCase
{
    public function testItPreservesExpandTitlesAndNestedRichBodies(): void
    {
        $html = <<<'HTML'
<p>Before</p>
<div class="expand-container"><div class="expand-control"><span class="expand-control-text">Outer &amp; [special] *title*</span></div>
<div class="expand-content"><p>First <strong>paragraph</strong>.</p>
<div class="expand-container"><div class="expand-control"><span class="expand-control-text">Nested</span></div><div class="expand-content"><ul><li>Child</li></ul></div></div>
<table><tr><th>Name</th></tr><tr><td>Value</td></tr></table>
<pre data-syntaxhighlighter-params="brush: text;">&amp;lt;div&amp;gt; - Getting issue details... STATUS</pre><p>Last paragraph</p>
</div></div><p>After</p>
HTML;
        $markdown = ConfluenceContentConverterFactory::create()->toMarkdown($html);
        self::assertStringContainsString('> [!note]- Outer &', $markdown);
        self::assertStringContainsString('> > [!note]- Nested', $markdown);
        self::assertStringNotContainsString('expand-control', $markdown);
        $reader = new ExportNoteReader(new FrontmatterParser(), '/unused');
        $converter = new ExportContentConverter(new ExportLinkResolver($reader, new ConfluencePageUrl('https://example.atlassian.net')));
        $result = $converter->convert(new ExportNote('/unused/note.md', '/unused', '123', $markdown));
        self::assertSame(2, substr_count($result->storage, 'ac:name="expand"'));
        self::assertStringContainsString('Outer &amp; [special] *title*</ac:parameter>', $result->storage);
        self::assertStringContainsString('<table>', $result->storage);
        self::assertStringContainsString('&amp;lt;div&amp;gt; - Getting issue details... STATUS', $result->storage);
        self::assertStringContainsString('<p>After</p>', $result->storage);
    }
}
