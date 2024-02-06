<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__,
    ])
    ->exclude([
      '.git',
      'vendor',
    ])
/*    ->notPath([
        'dump.php',
        'src/exception_file.php',
    ]) */
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        '@PHP74Migration' => true,
//        '@PhpCsFixer' => true,
//        '@PSR12' => true,
//        '@PER-CS' => true,
//        'array_syntax' => ['syntax' => 'short'],
        'method_argument_space' => false,
        'heredoc_indentation' => false,
        'trailing_comma_in_multiline' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setUsingCache(true)
    ->setHideProgress(false)
    //->setRiskyAllowed(false)
;
