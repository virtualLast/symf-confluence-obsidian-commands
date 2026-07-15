<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceHtmlCleaner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfluenceHtmlCleanerTest extends TestCase
{
    private ConfluenceHtmlCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new ConfluenceHtmlCleaner();
    }

    /**
     * What: Embedded style elements are removed from Confluence HTML.
     * How: HTML containing a style block is cleaned and compared with the content-only result.
     * Why: Page-level CSS is irrelevant to Markdown and could leak unwanted text into conversion.
     */
    #[Test]
    public function itRemovesStyleElements(): void
    {
        // Arrange
        $dirty = '<html><style>body { background-color: red; }</style><p>Test</p></html>';
        $expected = '<p>Test</p>';
        // Act
        $clean = $this->cleaner->clean($dirty);
        // Assert
        self::assertStringNotContainsString('style', $clean);
        self::assertSame($expected, $clean);
    }

    /**
     * What: Empty Confluence paragraphs are removed while neighbouring content remains.
     * How: A self-closing empty paragraph between two populated paragraphs is cleaned and inspected.
     * Why: Placeholder paragraphs create noise and excess spacing in generated Markdown.
     */
    #[Test]
    public function itRemovesEmptyParagraphs(): void
    {
        $dirty = '<p>Before</p><p local-id="empty" /><p>After</p>';

        $clean = $this->cleaner->clean($dirty);

        self::assertStringNotContainsString('local-id="empty"', $clean);
        self::assertStringContainsString('<p>Before</p>', $clean);
        self::assertStringContainsString('<p>After</p>', $clean);
    }

    /**
     * What: Unknown-attachment image wrappers and placeholders are removed.
     * How: A class-marked placeholder between paragraphs is cleaned and its absence is asserted.
     * Why: Broken Confluence attachment markers should not become broken images in Obsidian.
     */
    #[Test]
    public function itRemovesUnknownAttachmentImages(): void
    {
        // Arrange
        $dirty = <<<'HTML'
<p>Before</p>
<span class="confluence-embedded-file-wrapper">
    <img
        class="confluence-embedded-file unknown-attachment"
        src="/wiki/plugins/servlet/confluence/placeholder/unknown-attachment?locale=en_US"
    >
</span>
<p>After</p>
HTML;

        // Act
        $clean = $this->cleaner->clean($dirty);

        // Assert
        self::assertStringNotContainsString('unknown-attachment', $clean);
        self::assertStringNotContainsString(
            '/wiki/plugins/servlet/confluence/placeholder/unknown-attachment',
            $clean,
        );
        self::assertStringContainsString('<p>Before</p>', $clean);
        self::assertStringContainsString('<p>After</p>', $clean);
    }

    /**
     * What: Attachment placeholders are detected and removed by URL as well as by class.
     * How: A normally classed image with an unknown-attachment URL is cleaned and inspected.
     * Why: Confluence does not always apply the marker class, so URL detection prevents leaks.
     */
    #[Test]
    public function itRemovesAttachmentPlaceholderImagesByUrl(): void
    {
        // Arrange
        $dirty = <<<'HTML'
<p>Before</p>
<img
    class="confluence-embedded-image image-center"
    alt="example.png"
    src="https://example.atlassian.net/wiki/plugins/servlet/confluence/placeholder/unknown-attachment?locale=en_US&amp;version=2"
>
<p>After</p>
HTML;

        // Act
        $clean = $this->cleaner->clean($dirty);

        // Assert
        self::assertStringNotContainsString(
            '/placeholder/unknown-attachment',
            $clean,
        );

        self::assertStringContainsString('<p>Before</p>', $clean);
        self::assertStringContainsString('<p>After</p>', $clean);
    }

    /**
     * What: Decorative controls for expandable Confluence sections are removed.
     * How: An expand container is cleaned and its label, icon, and control markup are checked.
     * Why: Static Markdown cannot use Confluence's interactive controls and should omit their clutter.
     */
    #[Test]
    public function itRemovesExpandControls(): void
    {
        // Arrange
        $dirty = <<<'HTML'
<div class="expand-container">
    <div class="expand-control">
        <span class="expand-control-icon">
            <img
                class="expand-control-image"
                src="https://example.com/grey_arrow_down.png"
            >
        </span>
        <span class="expand-control-text">expand here</span>
    </div>
    <div class="expand-content">
        <p>Expanded content</p>
    </div>
</div>
HTML;

        // Act
        $clean = $this->cleaner->clean($dirty);

        // Assert
        self::assertStringNotContainsString('expand-control', $clean);
        self::assertStringNotContainsString('expand here', $clean);
        self::assertStringNotContainsString('grey_arrow_down.png', $clean);
    }

    /**
     * What: Content revealed by an expandable Confluence section is preserved.
     * How: The container is cleaned and its heading, paragraph, and content wrapper are asserted.
     * Why: Removing the interactive control must not also discard useful documentation.
     */
    #[Test]
    public function itPreservesExpandedContent(): void
    {
        // Arrange
        $dirty = <<<'HTML'
<div class="expand-container">
    <div class="expand-control">
        <span class="expand-control-text">expand here</span>
    </div>
    <div class="expand-content">
        <h2>Deployment Instructions</h2>
        <p>Deploy McBain before the Portal.</p>
    </div>
</div>
HTML;

        // Act
        $clean = $this->cleaner->clean($dirty);

        // Assert
        self::assertStringContainsString(
            '<div class="expand-content">',
            $clean,
        );
        self::assertStringContainsString(
            '<h2>Deployment Instructions</h2>',
            $clean,
        );
        self::assertStringContainsString(
            '<p>Deploy McBain before the Portal.</p>',
            $clean,
        );
    }

    /**
     * What: UTF-8 punctuation survives HTML cleaning without mojibake.
     * How: Curly punctuation, an arrow, and an em dash are cleaned and checked exactly.
     * Why: Imported documentation must preserve human-readable international text and symbols.
     */
    #[Test]
    public function itPreservesUtf8Characters(): void
    {
        // Arrange
        $dirty = <<<'HTML'
<p>User’s identity → application — end-to-end.</p>
HTML;

        // Act
        $clean = $this->cleaner->clean($dirty);

        // Assert
        self::assertStringContainsString(
            'User’s identity → application — end-to-end.',
            $clean,
        );

        self::assertStringNotContainsString('â', $clean);
    }
}
