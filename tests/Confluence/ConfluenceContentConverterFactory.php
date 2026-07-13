<?php

namespace App\Tests\Confluence;

use App\Confluence\ConfluenceContentConverter;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final class ConfluenceContentConverterFactory
{
    public static function create(): ConfluenceContentConverter
    {

        $htmlConverter = new HtmlConverter([
            'header_style' => 'atx',
            'table_pipe_escape' => '\|',
            'strip_tags' => true,
            'remove_nodes' => 'style script',
        ]);

        $htmlConverter
            ->getEnvironment()
            ->addConverter(new TableConverter());

        return new ConfluenceContentConverter($htmlConverter);
    }
}
