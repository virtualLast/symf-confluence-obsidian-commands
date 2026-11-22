<?php

declare(strict_types=1);

namespace App\Confluence\Export;

use League\CommonMark\Node\Block\AbstractBlock;

final class ExpandBlock extends AbstractBlock
{
    /** Carry a plain-text title and nested parsed Markdown blocks. */
    public function __construct(public readonly string $title)
    {
        parent::__construct();
    }
}
