<?php

declare(strict_types=1);

namespace App\Obsidian\Frontmatter;

final readonly class ParsedConfluenceFrontmatter
{
    public const string STATUS_VALID = 'valid';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_INVALID = 'invalid';

    /** @param list<ConfluenceRelationship> $relationships */
    private function __construct(
        public string $status,
        public ?string $pageId = null,
        public array $relationships = [],
        public ?string $error = null,
    ) {}

    /** @param list<ConfluenceRelationship> $relationships */
    public static function valid(string $pageId, array $relationships): self
    {
        return new self(self::STATUS_VALID, $pageId, $relationships);
    }

    public static function skipped(): self
    {
        return new self(self::STATUS_SKIPPED);
    }

    public static function invalid(string $error): self
    {
        return new self(self::STATUS_INVALID, error: $error);
    }
}
