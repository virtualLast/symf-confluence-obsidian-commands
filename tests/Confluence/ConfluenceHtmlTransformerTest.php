<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceHtmlTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfluenceHtmlTransformerTest extends TestCase
{

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

}
