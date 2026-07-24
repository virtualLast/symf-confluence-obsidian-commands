<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceSdkClientFactory;
use PHPUnit\Framework\TestCase;
use VirtualLast\ConfluenceV2\ConfluenceClient;

final class ConfluenceSdkClientFactoryTest extends TestCase
{
    public function testItCreatesTheV2ClientFromASiteRoot(): void
    {
        $client = new ConfluenceSdkClientFactory(
            'https://example.atlassian.net',
            'user@example.com',
            'token',
        )->create();

        self::assertInstanceOf(ConfluenceClient::class, $client);
    }

    public function testItRejectsTheLegacyWikiBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS site root');

        new ConfluenceSdkClientFactory(
            'https://example.atlassian.net/wiki/',
            'user@example.com',
            'token',
        )->create();
    }
}
