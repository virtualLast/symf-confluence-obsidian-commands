<?php

namespace App\Tests\Confluence;

trait ConfluenceFixtureTrait
{
    private function fixture(string $filename): string
    {
        $path = sprintf(
            '%s/../Fixtures/Confluence/%s',
            __DIR__,
            $filename,
        );

        $content = file_get_contents($path);

        self::assertNotFalse(
            $content,
            sprintf('Unable to read fixture: %s', $path),
        );

        return $content;
    }
}
