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

    #[Test]
    public function itRemovesEmptyParagraphs(): void
    {
        $dirty = '<p>Before</p><p local-id="empty" /><p>After</p>';

        $clean = $this->cleaner->clean($dirty);

        self::assertStringNotContainsString('local-id="empty"', $clean);
        self::assertStringContainsString('<p>Before</p>', $clean);
        self::assertStringContainsString('<p>After</p>', $clean);
    }

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
