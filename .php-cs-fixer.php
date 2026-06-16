<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
        'cast_spaces' => ['space' => 'none'],
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute', 'break', 'case', 'continue', 'curly_brace_block',
                'default', 'extra', 'parenthesis_brace_block', 'return',
                'square_brace_block', 'switch', 'throw', 'use',
            ],
        ],
        'yoda_style' => false,
        'binary_operator_spaces' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'single_quote' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'concat_space' => ['spacing' => 'one'],
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'ordered_types' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'types_spaces' => ['space_multiple_catch' => 'single'],
        'single_line_empty_body' => false,
    ])
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setUsingCache(true)
    ->setFinder($finder);
