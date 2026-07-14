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
}
