<?php

declare(strict_types=1);

namespace App\Tests\Obsidian\Frontmatter;

use App\Obsidian\Frontmatter\FrontmatterParser;
use App\Obsidian\Frontmatter\ParsedConfluenceFrontmatter;
use PHPUnit\Framework\TestCase;

final class FrontmatterParserTest extends TestCase
{
    public function testItParsesStringIdentityAndRelationships(): void
    {
        $content = <<<'MARKDOWN'
            ---
            source: confluence
            confluence:
              pageId: "999999999999999999999999"
            relationships:
              linkedPages:
                - pageId: "456"
                  title: Target
                  url: https://example.atlassian.net/wiki/pages/456
            ---
            Body
            MARKDOWN;

        $result = new FrontmatterParser()->parse($content);

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_VALID, $result->status);
        self::assertSame('999999999999999999999999', $result->pageId);
        self::assertCount(1, $result->relationships);
        self::assertSame('456', $result->relationships[0]->pageId);
    }

    public function testItSkipsANormalMarkdownNote(): void
    {
        $result = new FrontmatterParser()->parse("---\nsource: personal\n---\nBody");

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_SKIPPED, $result->status);
    }

    public function testItMarksMalformedYamlAsInvalid(): void
    {
        $result = new FrontmatterParser()->parse("---\nsource: [broken\n---\nBody");

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_INVALID, $result->status);
        self::assertStringContainsString('Malformed YAML', $result->error ?? '');
    }

    public function testItMarksAConfluenceNoteWithoutAnIdAsInvalid(): void
    {
        $result = new FrontmatterParser()->parse("---\nsource: confluence\nconfluence: {}\n---\nBody");

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_INVALID, $result->status);
        self::assertStringContainsString('pageId', $result->error ?? '');
    }

    public function testItAcceptsMissingRelationshipsAsEmpty(): void
    {
        $result = new FrontmatterParser()->parse(
            "---\r\nsource: confluence\r\nconfluence:\r\n  pageId: '123'\r\n---\r\nBody",
        );

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_VALID, $result->status);
        self::assertSame([], $result->relationships);
    }

    public function testItRejectsMalformedRelationships(): void
    {
        $result = new FrontmatterParser()->parse(
            "---\nsource: confluence\nconfluence:\n  pageId: '123'\nrelationships:\n  linkedPages: bad\n---\nBody",
        );

        self::assertSame(ParsedConfluenceFrontmatter::STATUS_INVALID, $result->status);
        self::assertStringContainsString('linkedPages', $result->error ?? '');
    }
}
