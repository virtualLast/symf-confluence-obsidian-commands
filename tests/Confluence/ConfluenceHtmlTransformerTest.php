<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceHtmlTransformer;
use App\Tests\Traits\FixtureTrait;
use DOMDocument;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfluenceHtmlTransformerTest extends TestCase
{
    use FixtureTrait;

    private ConfluenceHtmlTransformer $htmlTransformer;

    public function setUp(): void
    {
        $this->htmlTransformer = new ConfluenceHtmlTransformer();
    }

    /**
     * What: Top-level anchors are wrapped in paragraphs before Markdown conversion.
     * How: A root anchor followed by a paragraph is transformed and both structures are asserted.
     * Why: Paragraph wrapping prevents standalone links from merging with neighbouring content.
     */
    #[Test]
    public function itWrapsTopLevelAnchorsInParagraphs(): void
    {
        $html = <<<'HTML'
<a href="https://example.com">Example</a>
<p>Following content</p>
HTML;

        $transformed = $this->htmlTransformer->transform($html);

        self::assertStringContainsString(
            '<p><a href="https://example.com">Example</a></p>',
            $transformed,
        );

        self::assertStringContainsString(
            '<p>Following content</p>',
            $transformed,
        );
    }

    #[Test]
    public function itTransformsConfluenceCodeBlocksIntoSemanticCodeBlocks(): void
    {
        $html = <<<'HTML'
<div class="code panel pdl">
    <div class="codeContent panelContent pdl">
        <pre class="syntaxhighlighter-pre" data-syntaxhighlighter-params="brush: js; gutter: false; theme: Confluence" data-theme="Confluence">function loremIpsum(name) {
    const message = `Hello, ${name}!`;
}</pre>
    </div>
</div>
HTML;

        self::assertSame(
            '<pre><code class="language-javascript">function loremIpsum(name) {' . "\n"
            . '    const message = `Hello, ${name}!`;' . "\n"
            . '}</code></pre>',
            $this->htmlTransformer->transform($html),
        );
    }

    #[Test]
    public function itMapsCommonConfluenceBrushAliases(): void
    {
        foreach (
            ['java' => 'markdown', 'ts' => 'typescript', 'sh' => 'bash', 'yml' => 'yaml'] as $brush => $language
        ) {
            $html = sprintf(
                '<pre data-syntaxhighlighter-params="gutter: false; brush: %s;">code</pre>',
                $brush,
            );

            self::assertSame(
                sprintf('<pre><code class="language-%s">code</code></pre>', $language),
                $this->htmlTransformer->transform($html),
            );
        }
    }

    #[Test]
    public function itLeavesCodeBlocksWithoutABrushUnchanged(): void
    {
        $html = '<pre class="syntaxhighlighter-pre" '
            . 'data-syntaxhighlighter-params="gutter: false; theme: Confluence">code</pre>';

        self::assertSame($html, $this->htmlTransformer->transform($html));
    }

    /**
     * What: A standalone link inside a container receives its own paragraph.
     * How: Mixed standalone and already-wrapped links are transformed and inspected independently.
     * Why: Each logical link block needs separation for predictable Markdown output.
     */
    #[Test]
    public function itWrapsStandaloneLinksInParagraphs(): void
    {
        // Arrange
        $html = <<<'HTML'
<div class="innerCell">
    <a
        class="external-link"
        href="https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641"
    >https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641</a>
    <p>
        <a href="https://example.com/questions">Questions for Tesco</a>
    </p>
</div>
HTML;

        // Act
        $transformed = $this->htmlTransformer->transform($html);

        // Assert
        self::assertStringContainsString(
            '<p><a class="external-link" href="https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641">'
            . 'https://github.com/AshwoodsLightfoot/Gen2-ExternalAPI/pull/641'
            . '</a></p>',
            $transformed,
        );

        self::assertStringContainsString(
            '<p>'
            . "\n"
            . '        <a href="https://example.com/questions">Questions for Tesco</a>'
            . "\n"
            . '    </p>',
            $transformed,
        );
    }

    /**
     * What: Links already contained in paragraphs are not wrapped a second time.
     * How: An inline link is transformed and checked for preserved markup and no nested paragraph.
     * Why: Double wrapping creates invalid HTML and can corrupt downstream Markdown conversion.
     */
    #[Test]
    public function itDoesNotWrapLinksAlreadyInsideParagraphs(): void
    {
        // Arrange
        $html = <<<'HTML'
<p>
    Read the <a href="https://example.com/docs">documentation</a>.
</p>
HTML;

        // Act
        $transformed = $this->htmlTransformer->transform($html);

        // Assert
        self::assertStringContainsString(
            '<p>'
            . "\n"
            . '    Read the <a href="https://example.com/docs">documentation</a>.'
            . "\n"
            . '</p>',
            $transformed,
        );

        self::assertStringNotContainsString(
            '<p><p>',
            $transformed,
        );
    }

    /**
     * What: Empty Confluence list-item wrappers are removed without flattening valid nesting.
     * How: The exported HTML fixture is transformed and compared with the normalized DOM fixture.
     * Why: Wrapper items otherwise produce duplicate Markdown list markers during conversion.
     *
     * DOM manipulation preserves the original whitespace text nodes when elements are moved.
     * The resulting HTML is structurally identical to the expected fixture, but its
     * indentation can differ.
     */
    #[Test]
    public function itNormalisesEmptyListItems(): void
    {
        self::assertXmlStringEqualsXmlString(
            $this->fixture('normalise-nested-list.expected.html'),
            $this->htmlTransformer->transform(
                $this->fixture('normalise-nested-list.input.html'),
            ),
        );
    }

    /**
     * What: Correctly formed nested lists remain unchanged.
     * How: A parent item containing meaningful content and a child list is transformed and compared exactly.
     * Why: Normalization must target only empty Confluence wrappers and preserve valid list structures.
     */
    #[Test]
    public function itPreservesCorrectlyFormedNestedLists(): void
    {
        $html = '<ul><li><p>Parent</p><ul><li><p>Child</p></li></ul></li></ul>';

        self::assertSame($html, $this->htmlTransformer->transform($html));
    }

    /**
     * What: Standalone top-level image content is wrapped in a paragraph.
     * How: The exported HTML fixture is transformed and compared with the expected HTML structure.
     * Why: A block-level wrapper keeps the image separate from adjacent content during Markdown conversion.
     */
    #[Test]
    public function itWrapsStandaloneImageContent(): void
    {
        self::assertSame(
            $this->canonicalHtml($this->fixture('wrap-standalone-image.expected.html')),
            $this->canonicalHtml(
                $this->htmlTransformer->transform(
                    $this->fixture('wrap-standalone-image.input.html'),
                ),
            ),
        );
    }

    private function canonicalHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<div id="fixture-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        $xpath = new DOMXPath($document);
        $whitespaceNodes = $xpath->query('//text()[normalize-space(.) = ""]');

        if ($whitespaceNodes !== false) {
            foreach (iterator_to_array($whitespaceNodes) as $whitespaceNode) {
                if (!$whitespaceNode instanceof DOMNode) {
                    continue;
                }

                $whitespaceNode->parentNode?->removeChild($whitespaceNode);
            }
        }

        return $document->C14N();
    }

}
