<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->name('*.php')
    ->in([__DIR__])
    ->exclude('cache')
    ->exclude('include')
    ->exclude('node_modules')
    ->exclude('public')
    ->exclude('storage')
    ->exclude('vendor');

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x2Migration:risky' => true,

        // required by PSR-12
        'concat_space' => [
            'spacing' => 'one',
        ],

        // disable some too strict rules
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
        ],
        'native_constant_invocation' => [
            'include' => [
            ],
        ],
        'native_function_invocation' => false,
        'void_return' => false,
        'combine_consecutive_issets' => false,
        'combine_consecutive_unsets' => false,
        'multiline_whitespace_before_semicolons' => false,
        'ordered_class_elements' => false,
        'return_assignment' => false,
        'comment_to_phpdoc' => false,
        'use_arrow_functions' => false,
        'blank_line_before_statement' => false,
        'declare_strict_types' => false,
        'increment_style' => [
            'style' => 'post',
        ],
        'strict_comparison' => false,
        'single_quote' => false,
        'strict_param' => false,

        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'php_unit_test_case_static_method_calls' => false,

        'phpdoc_add_missing_param_annotation' => false,
        'phpdoc_summary' => false,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'phpdoc_types' => [
            'groups' => ['simple', 'alias'],
        ],

        // https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/issues/8628
        'fully_qualified_strict_types' => false,

        // Traits must be in specific order, not alphabetical (e.g. App\Resource)
        'ordered_traits' => false,

        // HTTP macros cannot be static
        'static_lambda' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.' . md5(__DIR__) . '.cache');
