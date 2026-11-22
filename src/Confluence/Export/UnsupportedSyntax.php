<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Node\Inline\AbstractInline;

final class UnsupportedSyntax extends AbstractInline
{
    /** Retain unsupported source until image subtrees have been excluded. */
    public function __construct(public readonly string $literal)
    {
        parent::__construct();
    }
}
