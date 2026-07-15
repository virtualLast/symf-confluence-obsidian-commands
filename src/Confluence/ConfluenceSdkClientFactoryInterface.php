<?php

declare(strict_types=1);

namespace App\Confluence;

use CloudPlayDev\ConfluenceClient\ConfluenceClient;

interface ConfluenceSdkClientFactoryInterface
{
    public function create(): ConfluenceClient;
}
