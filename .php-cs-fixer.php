<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/bin',
    ])
    ->exclude([
        'var',
        'vendor',
        'build',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        // Base ruleset
        '@PSR12' => true,
        '@Symfony' => true,
        '@PhpCsFixer' => true,

        // Array formatting
        'array_syntax' => ['syntax' => 'short'],
        'array_indentation' => true,
        'trim_array_spaces' => true,

        // Import optimization
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const']
        ],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // Code structure
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,

        // Formatting
        'concat_space' => ['spacing' => 'one'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'unary_operator_spaces' => true,
        'cast_spaces' => true,

        // PHP 8+ features
        'modernize_types_casting' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Disable some opinionated rules
        'yoda_style' => false,
        'increment_style' => false,
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,

        // CLI specific
        'echo_tag_syntax' => false, // Allow echo in CLI context
        'single_line_throw' => false, // Allow multiline throws for readability
    ])
    ->setFinder($finder);