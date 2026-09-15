<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->name('*.php')
    ->exclude('tools')
    ->notPath('vendor')
    ->notName('.php-cs-fixer.php');

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(false);
