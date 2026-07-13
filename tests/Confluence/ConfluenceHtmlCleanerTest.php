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
    public function itRemovesUnknownAttachmentImages(): void
    {
        // Arrange
        // Act
        $this->cleaner->clean('<ac:image><ri:attachment ri:filename="unknown.png"/></ac:image>');
        // Assert
    }

    #[Test]
    public function itRemovesExpandControls(): void
    {
        // Arrange
        // Act
        // Assert
    }

    #[Test]
    public function itPreservesExpandedContent(): void
    {
        // Arrange
        // Act
        // Assert
    }
}
