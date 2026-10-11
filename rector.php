<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\ExplicitAttributeNamedArgsRector;
use Rector\Config\RectorConfig;

// Le jeu PHP suit le plancher déclaré dans composer.json : garder les deux en phase.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withRootFiles()
    ->withPhpSets(php84: true)
    ->withAttributesSets(phpunit: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, namedArgs: true, earlyReturn: true)
    ->withSkip([
        // Les attributs de PHPUnit refusent les arguments nommés (@no-named-arguments).
        ExplicitAttributeNamedArgsRector::class,
    ])
;
