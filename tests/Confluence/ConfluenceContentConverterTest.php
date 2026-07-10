<?php

namespace App\Tests\Confluence;


use App\Confluence\ConfluenceContentConverter;
use PHPUnit\Framework\TestCase;

class ConfluenceContentConverterTest extends TestCase
{
    public function testConvertsHtmlTableToMarkdown(): void
    {
        $converter = $this->createConfluenceContentConverter();

        $result = $converter->toMarkdown(
            <<<HTML
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Page one</td>
                    <td>Complete</td>
                </tr>
            </tbody>
        </table>
        HTML,
        );

        $this->assertSame(
            <<<'MARKDOWN'
        | Name | Status |
        |---|---|
        | Page one | Complete |
        MARKDOWN,
            $result
        );
    }

    private function createConfluenceContentConverter(): ConfluenceContentConverter
    {
        return ConfluenceContentConverterFactory::create();
    }
}
