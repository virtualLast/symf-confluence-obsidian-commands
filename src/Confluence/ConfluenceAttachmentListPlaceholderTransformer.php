<?php

declare(strict_types=1);

namespace App\Confluence;

use DOMElement;
use DOMXPath;

final class ConfluenceAttachmentListPlaceholderTransformer extends AbstractConfluenceDomDocument
{
    public const string PLACEHOLDER = '@@OBSIDIAN_ATTACHMENT_LIST@@';

    public function transform(string $html): string
    {
        $document = $this->loadFragment($html);
        $xpath = new DOMXPath($document);
        $errors = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " error ")]',
        );

        if ($errors === false) {
            return $html;
        }

        foreach (iterator_to_array($errors) as $error) {
            if (!$error instanceof DOMElement || !str_contains($error->textContent, "Error rendering macro 'attachments'")) {
                continue;
            }

            $paragraph = $document->createElement('p');
            $paragraph->appendChild($document->createTextNode(self::PLACEHOLDER));
            $error->parentNode?->replaceChild($paragraph, $error);
        }

        return $this->innerHtml($document->documentElement);
    }
}
