<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'ordered_imports' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
