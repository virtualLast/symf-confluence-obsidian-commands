<?php

declare(strict_types=1);

namespace App\Confluence;

use VirtualLast\ConfluenceV2\ConfluenceClient;

interface ConfluenceSdkClientFactoryInterface
{
    public function create(): ConfluenceClient;
}
