<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ImportedLinkedPage;
use DOMElement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ConfluenceLinkedPageExtractor extends AbstractConfluenceDomDocument
{
    public function __construct(
        #[Autowire(env: 'CONFLUENCE_BASE_URL')]
        private readonly string $confluenceBaseUrl,
    ) {}

    /** @return list<ImportedLinkedPage> */
    public function extract(string $html): array
    {
        $document = $this->loadFragment($html);
        $linkedPages = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            $linkedPage = $this->extractLink($anchor);

            if ($linkedPage === null) {
                continue;
            }

            $pageKey = 'page:' . $linkedPage->id;

            if (isset($linkedPages[$pageKey])) {
                continue;
            }

            $linkedPages[$pageKey] = $linkedPage;
        }

        return array_values($linkedPages);
    }

    private function extractLink(DOMElement $anchor): ?ImportedLinkedPage
    {
        $href = trim($anchor->getAttribute('href'));

        if ($href === '') {
            return null;
        }

        $resourceType = trim($anchor->getAttribute('data-linked-resource-type'));
        $resourceId = trim($anchor->getAttribute('data-linked-resource-id'));
        $pageId = $this->positiveInteger($resourceId);

        if ($resourceType !== '' && $resourceType !== 'page') {
            return null;
        }

        if ($pageId === null) {
            $pageId = $this->pageIdFromUrl($href);
        }

        if ($pageId === null) {
            return null;
        }

        $title = $this->firstNonEmpty(
            $anchor->getAttribute('data-linked-resource-default-alias'),
            $anchor->getAttribute('data-linked-resource-title'),
            $anchor->getAttribute('title'),
            $anchor->textContent,
        );

        return new ImportedLinkedPage(
            id: $pageId,
            title: $title,
            url: $this->absoluteUrl($href),
        );
    }

    private function pageIdFromUrl(string $url): ?string
    {
        if (!$this->isConfluenceUrl($url)) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $parameters);
            $pageId = $this->positiveInteger($parameters['pageId'] ?? null);

            if ($pageId !== null) {
                return $pageId;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && preg_match('~/pages/(\d+)(?:/|$)~', $path, $matches) === 1) {
            return $this->positiveInteger($matches[1]);
        }

        return null;
    }

    private function isConfluenceUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null) {
            return str_starts_with($url, '/') && !str_starts_with($url, '//');
        }

        return is_string($host)
            && strcasecmp($host, (string) parse_url($this->confluenceBaseUrl, PHP_URL_HOST)) === 0;
    }

    private function absoluteUrl(string $url): string
    {
        if (parse_url($url, PHP_URL_HOST) !== null) {
            return $url;
        }

        $base = parse_url($this->confluenceBaseUrl);
        $origin = sprintf(
            '%s://%s%s',
            $base['scheme'] ?? 'https',
            $base['host'] ?? '',
            isset($base['port']) ? ':' . $base['port'] : '',
        );

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        return rtrim($this->confluenceBaseUrl, '/') . '/' . $url;
    }

    private function positiveInteger(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $pageId = (string) $value;

        return preg_match('/^[1-9]\d*$/', $pageId) === 1 ? $pageId : null;
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
