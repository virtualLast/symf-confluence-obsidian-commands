<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceAttachmentListPlaceholderTransformer;
use PHPUnit\Framework\TestCase;

final class ConfluenceAttachmentListPlaceholderTransformerTest extends TestCase
{
    public function testItReplacesTheAttachmentsMacroErrorAtItsOriginalPosition(): void
    {
        $html = '<p>Before</p><div class="error">Error rendering macro \'attachments\' : failure</div><p>After</p>';

        self::assertSame(
            '<p>Before</p><p>@@OBSIDIAN_ATTACHMENT_LIST@@</p><p>After</p>',
            (new ConfluenceAttachmentListPlaceholderTransformer())->transform($html),
        );
    }

    public function testItLeavesOtherErrorsUntouched(): void
    {
        $html = '<div class="error">Some other error</div>';

        self::assertSame(
            $html,
            (new ConfluenceAttachmentListPlaceholderTransformer())->transform($html),
        );
    }
}
