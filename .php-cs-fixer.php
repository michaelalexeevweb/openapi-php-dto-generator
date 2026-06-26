<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->registerCustomFixers(new PhpCsFixerCustomFixers\Fixers())
    ->setRules([
        'multiline_promoted_properties' => true,
        PhpCsFixerCustomFixers\Fixer\NoPhpStormGeneratedCommentFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessCommentFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoTrailingCommaInSinglelineFixer::name() => true,
        '@PER-CS' => true,
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        '@PhpCsFixer' => true,
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
        'method_chaining_indentation' => true,
        'array_indentation' => true,
        'no_useless_else' => true,
        'return_assignment' => ['skip_named_var_tags' => true],
        'concat_space' => ['spacing' => 'one'],
        'phpdoc_tag_type' => false,
        'phpdoc_types_order' => false,
        'phpdoc_separation' => false,
        'single_line_comment_style' => false,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => true,
        'no_space_around_double_colon' => false,
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'phpdoc_to_comment' => false,
        'phpdoc_summary' => false,
        'no_multiline_whitespace_around_double_arrow' => false,
        'types_spaces' => ['space_multiple_catch' => 'single'],
        'class_definition' => ['space_before_parenthesis' => true],
        'octal_notation' => false,
        'single_line_empty_body' => false,
        'ordered_types' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'ordered_class_elements' => false,
        'no_blank_lines_after_phpdoc' => false,
        'increment_style' => false,
        'class_attributes_separation' => false,
        'blank_line_before_statement' => false,
        'single_line_comment_spacing' => false,
        'multiline_whitespace_before_semicolons' => false,
        'heredoc_indentation' => false,
        'operator_linebreak' => false,
        'attribute_block_no_spaces' => false,
        'phpdoc_align' => false,
    ])
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setUsingCache(false)
    ->setRiskyAllowed(false)
    ->setFinder($finder);
