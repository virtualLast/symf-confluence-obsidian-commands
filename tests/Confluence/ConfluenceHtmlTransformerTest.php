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
