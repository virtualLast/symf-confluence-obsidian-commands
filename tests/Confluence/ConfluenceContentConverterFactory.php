<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceContentConverter;
use App\Confluence\ConfluenceStoragePreprocessor;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final class ConfluenceContentConverterFactory
{
    public static function create(): ConfluenceContentConverter
    {
        $processor = new ConfluenceStoragePreprocessor();

        $htmlConverter = new HtmlConverter([
            'header_style' => 'atx',
            'table_pipe_escape' => '\|',
        ]);

        $htmlConverter
            ->getEnvironment()
            ->addConverter(new TableConverter());

        return new ConfluenceContentConverter($processor, $htmlConverter);
    }
}
