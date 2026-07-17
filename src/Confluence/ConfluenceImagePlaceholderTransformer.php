<?php

declare(strict_types=1);

namespace App\Confluence;

use App\Model\ImportedAttachment;
use DOMElement;
use DOMXPath;

final class ConfluenceImagePlaceholderTransformer extends AbstractConfluenceDomDocument
{
    public const string UNRESOLVED_ATTACHMENT_PLACEHOLDER = '@@OBSIDIAN-UNRESOLVED-ATTACHMENT@@';

    /** @param list<ImportedAttachment> $attachments */
    public function transform(string $html, array $attachments): string
    {
        $document = $this->loadFragment($html);
        $xpath = new DOMXPath($document);
        $images = $xpath->query('//img');

        if ($images === false) {
            return $html;
        }

        foreach (iterator_to_array($images) as $image) {
            if (!$image instanceof DOMElement || !$this->isInternalImage($image)) {
                continue;
            }

            $attachment = $this->matchingAttachment($image, $attachments);

            if ($attachment === null) {
                if ($this->isUnknownAttachment($image)) {
                    $image->parentNode?->replaceChild(
                        $document->createTextNode(self::UNRESOLVED_ATTACHMENT_PLACEHOLDER),
                        $image,
                    );
                }

                continue;
            }

            $placeholder = sprintf(
                '@@OBSIDIAN_ATTACHMENT:att%s@@',
                $this->normaliseAttachmentId($attachment->id),
            );
            $image->parentNode?->replaceChild(
                $document->createTextNode($placeholder),
                $image,
            );
        }

        return $this->innerHtml($document->documentElement);
    }

    private function isInternalImage(DOMElement $image): bool
    {
        return $image->hasAttribute('data-linked-resource-id')
            || $image->hasAttribute('data-linked-resource-default-alias')
            || str_contains($image->getAttribute('class'), 'confluence-embedded-image')
            || str_contains($image->getAttribute('src'), '/download/attachments/')
            || $this->isUnknownAttachment($image);
    }

    private function isUnknownAttachment(DOMElement $image): bool
    {
        return str_contains($image->getAttribute('class'), 'unknown-attachment')
            || str_contains($image->getAttribute('src'), '/placeholder/unknown-attachment');
    }

    /** @param list<ImportedAttachment> $attachments */
    private function matchingAttachment(DOMElement $image, array $attachments): ?ImportedAttachment
    {
        $resourceId = trim($image->getAttribute('data-linked-resource-id'));

        if ($resourceId !== '') {
            $normalisedResourceId = $this->normaliseAttachmentId($resourceId);

            foreach ($attachments as $attachment) {
                if ($this->normaliseAttachmentId($attachment->id) === $normalisedResourceId) {
                    return $attachment;
                }
            }

            return null;
        }

        $source = html_entity_decode(trim($image->getAttribute('src')));

        if ($source !== '') {
            foreach ($attachments as $attachment) {
                if ($attachment->downloadUrl !== null && $this->urlsMatch($source, $attachment->downloadUrl)) {
                    return $attachment;
                }
            }
        }

        $filename = trim($image->getAttribute('data-linked-resource-default-alias'));

        if ($filename === '') {
            $filename = trim($image->getAttribute('alt'));
        }

        if ($filename === '') {
            $path = parse_url($source, PHP_URL_PATH);
            $filename = is_string($path) ? rawurldecode(basename($path)) : '';
        }

        foreach ($attachments as $attachment) {
            if ($filename !== '' && $attachment->filename === $filename) {
                return $attachment;
            }
        }

        return null;
    }

    private function urlsMatch(string $source, string $downloadUrl): bool
    {
        if ($source === $downloadUrl) {
            return true;
        }

        $sourcePath = parse_url($source, PHP_URL_PATH);
        $downloadPath = parse_url($downloadUrl, PHP_URL_PATH);

        return is_string($sourcePath)
            && is_string($downloadPath)
            && $sourcePath === $downloadPath;
    }

    private function normaliseAttachmentId(string $id): string
    {
        return str_starts_with($id, 'att') ? substr($id, 3) : $id;
    }
}
