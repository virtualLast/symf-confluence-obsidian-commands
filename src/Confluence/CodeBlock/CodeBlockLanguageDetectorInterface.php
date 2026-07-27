<?php

declare(strict_types=1);

namespace App\Confluence\CodeBlock;

interface CodeBlockLanguageDetectorInterface
{
    public function detect(?string $declaredLanguage, string $code): ?string;
}
