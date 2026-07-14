<?php

namespace App\Confluence;

use DOMXPath;

abstract class AbstractConfluenceCleaner extends AbstractConfluenceDomDocument
{
    abstract public function clean(string $html): string;
    abstract protected function removeElements(DOMXPath $xpath, string $expression): void;
}
