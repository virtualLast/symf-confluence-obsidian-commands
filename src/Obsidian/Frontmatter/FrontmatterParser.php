<?php

declare(strict_types=1);

namespace App\Obsidian\Frontmatter;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class FrontmatterParser
{
    public function parse(string $content): ParsedConfluenceFrontmatter
    {
        if (preg_match('/\A---(?:\r\n|\n)/', $content) !== 1) {
            return ParsedConfluenceFrontmatter::skipped();
        }

        if (preg_match('/\A---(?:\r\n|\n)(.*?)(?:\r\n|\n)---(?:\r\n|\n|\z)/s', $content, $matches) !== 1) {
            return ParsedConfluenceFrontmatter::invalid('Frontmatter is missing its closing delimiter.');
        }

        try {
            $metadata = Yaml::parse($matches[1]);
        } catch (ParseException $exception) {
            return ParsedConfluenceFrontmatter::invalid(sprintf(
                'Malformed YAML: %s',
                $exception->getMessage(),
            ));
        }

        if (!is_array($metadata)) {
            return ParsedConfluenceFrontmatter::invalid('Frontmatter must contain a YAML mapping.');
        }

        if (($metadata['source'] ?? null) !== 'confluence') {
            return ParsedConfluenceFrontmatter::skipped();
        }

        $confluence = $metadata['confluence'] ?? null;

        if (!is_array($confluence)) {
            return ParsedConfluenceFrontmatter::invalid('Missing confluence metadata.');
        }

        $pageId = $this->pageId($confluence['pageId'] ?? null);

        if ($pageId === null) {
            return ParsedConfluenceFrontmatter::invalid('confluence.pageId must be a positive integer string.');
        }

        $relationshipMetadata = $metadata['relationships'] ?? [];

        if (!is_array($relationshipMetadata)) {
            return ParsedConfluenceFrontmatter::invalid('relationships must be a YAML mapping.');
        }

        $linkedPages = $relationshipMetadata['linkedPages'] ?? [];

        if (!is_array($linkedPages) || !array_is_list($linkedPages)) {
            return ParsedConfluenceFrontmatter::invalid('relationships.linkedPages must be a YAML sequence.');
        }

        $relationships = [];

        foreach ($linkedPages as $offset => $linkedPage) {
            if (!is_array($linkedPage)) {
                return ParsedConfluenceFrontmatter::invalid(sprintf(
                    'relationships.linkedPages.%d must be a mapping.',
                    $offset,
                ));
            }

            $linkedPageId = $this->pageId($linkedPage['pageId'] ?? null);
            $url = $linkedPage['url'] ?? null;
            $title = $linkedPage['title'] ?? '';

            if ($linkedPageId === null || !is_string($url) || trim($url) === '') {
                return ParsedConfluenceFrontmatter::invalid(sprintf(
                    'relationships.linkedPages.%d requires a valid pageId and URL.',
                    $offset,
                ));
            }

            if (!is_string($title)) {
                return ParsedConfluenceFrontmatter::invalid(sprintf(
                    'relationships.linkedPages.%d.title must be a string.',
                    $offset,
                ));
            }

            $relationships[] = new ConfluenceRelationship(
                $linkedPageId,
                $title,
                $url,
            );
        }

        return ParsedConfluenceFrontmatter::valid($pageId, $relationships);
    }

    private function pageId(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }
}
