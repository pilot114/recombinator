<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        // PHP version sets - use the latest
        LevelSetList::UP_TO_PHP_84,

        // Code quality sets
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,

        // PHPUnit improvements
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::PHPUNIT_110,
    ])
    ->withSkip([
        // Skip RemoveNonExistingVarAnnotationRector to preserve PHPStan type hints
        RemoveNonExistingVarAnnotationRector::class,

        // Skip PrettyDumper.php - intentionally overrides parent method return type
        __DIR__ . '/src/Core/PrettyDumper.php',
    ])
    ->withPhpSets(
        php84: true
    );