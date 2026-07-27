<?php

declare(strict_types=1);

namespace App\Tests\Confluence;

use App\Confluence\CodeBlock\CodeBlockLanguageDetector;
use App\Confluence\ConfluenceAttachmentListPlaceholderTransformer;
use App\Confluence\ConfluenceContentConverter;
use App\Confluence\ConfluenceHtmlCleaner;
use App\Confluence\ConfluenceHtmlTransformer;
use App\Confluence\ConfluenceImagePlaceholderTransformer;
use App\Markdown\MarkdownCleaner;
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

        return new ConfluenceContentConverter(
            new ConfluenceHtmlCleaner(),
            new ConfluenceHtmlTransformer(new CodeBlockLanguageDetector()),
            new ConfluenceImagePlaceholderTransformer(),
            new ConfluenceAttachmentListPlaceholderTransformer(),
            $htmlConverter,
            new MarkdownCleaner(),
        );
    }
}
