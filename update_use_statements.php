#!/usr/bin/env php
<?php

// Скрипт для обновления use statements во всех файлах

$srcDir = __DIR__ . '/src';

// Mapping классов к новым namespace
$classMap = [
    // Core
    'Parser' => 'Recombinator\\Core\\Parser',
    'Fluent' => 'Recombinator\\Core\\Fluent',
    'ExecutionCache' => 'Recombinator\\Core\\ExecutionCache',
    'PrettyDumper' => 'Recombinator\\Core\\PrettyDumper',

    // Analysis
    'CognitiveComplexityCalculator' => 'Recombinator\\Analysis\\CognitiveComplexityCalculator',
    'CyclomaticComplexityCalculator' => 'Recombinator\\Analysis\\CyclomaticComplexityCalculator',
    'ComplexityController' => 'Recombinator\\Analysis\\ComplexityController',
    'SideEffectClassifier' => 'Recombinator\\Analysis\\SideEffectClassifier',
    'VariableAnalyzer' => 'Recombinator\\Analysis\\VariableAnalyzer',
    'EffectDependencyGraph' => 'Recombinator\\Analysis\\EffectDependencyGraph',
    'PureBlockFinder' => 'Recombinator\\Analysis\\PureBlockFinder',
    'NestingDepthVisitor' => 'Recombinator\\Analysis\\NestingDepthVisitor',

    // Transformation
    'SideEffectSeparator' => 'Recombinator\\Transformation\\SideEffectSeparator',
    'AbstractionRecovery' => 'Recombinator\\Transformation\\AbstractionRecovery',
    'FunctionExtractor' => 'Recombinator\\Transformation\\FunctionExtractor',
    'NestedConditionSimplifier' => 'Recombinator\\Transformation\\NestedConditionSimplifier',
    'VariableDeclarationOptimizer' => 'Recombinator\\Transformation\\VariableDeclarationOptimizer',

    // Transformation/Visitor
    'BaseVisitor' => 'Recombinator\\Transformation\\Visitor\\BaseVisitor',
    'BinaryAndIssetVisitor' => 'Recombinator\\Transformation\\Visitor\\BinaryAndIssetVisitor',
    'CallFunctionVisitor' => 'Recombinator\\Transformation\\Visitor\\CallFunctionVisitor',
    'ConcatAssertVisitor' => 'Recombinator\\Transformation\\Visitor\\ConcatAssertVisitor',
    'ConstClassVisitor' => 'Recombinator\\Transformation\\Visitor\\ConstClassVisitor',
    'ConstructorAndMethodsVisitor' => 'Recombinator\\Transformation\\Visitor\\ConstructorAndMethodsVisitor',
    'EvalStandardFunction' => 'Recombinator\\Transformation\\Visitor\\EvalStandardFunction',
    'FunctionScopeVisitor' => 'Recombinator\\Transformation\\Visitor\\FunctionScopeVisitor',
    'IncludeVisitor' => 'Recombinator\\Transformation\\Visitor\\IncludeVisitor',
    'ParametersToArgsVisitor' => 'Recombinator\\Transformation\\Visitor\\ParametersToArgsVisitor',
    'PreExecutionVisitor' => 'Recombinator\\Transformation\\Visitor\\PreExecutionVisitor',
    'PropertyAccessVisitor' => 'Recombinator\\Transformation\\Visitor\\PropertyAccessVisitor',
    'RemoveVisitor' => 'Recombinator\\Transformation\\Visitor\\RemoveVisitor',
    'ScopeVisitor' => 'Recombinator\\Transformation\\Visitor\\ScopeVisitor',
    'SideEffectMarkerVisitor' => 'Recombinator\\Transformation\\Visitor\\SideEffectMarkerVisitor',
    'TernarReturnVisitor' => 'Recombinator\\Transformation\\Visitor\\TernarReturnVisitor',
    'ThisPropertyReplacer' => 'Recombinator\\Transformation\\Visitor\\ThisPropertyReplacer',
    'VarToScalarVisitor' => 'Recombinator\\Transformation\\Visitor\\VarToScalarVisitor',

    // Interactive
    'InteractiveCLI' => 'Recombinator\\Interactive\\InteractiveCLI',
    'InteractiveEditAnalyzer' => 'Recombinator\\Interactive\\InteractiveEditAnalyzer',
    'InteractiveEditResult' => 'Recombinator\\Interactive\\InteractiveEditResult',
    'EditSession' => 'Recombinator\\Interactive\\EditSession',
    'EditCandidate' => 'Recombinator\\Interactive\\EditCandidate',
    'ChangeHistory' => 'Recombinator\\Interactive\\ChangeHistory',

    // Domain
    'SideEffectType' => 'Recombinator\\Domain\\SideEffectType',
    'ComplexityMetrics' => 'Recombinator\\Domain\\ComplexityMetrics',
    'ComplexityComparison' => 'Recombinator\\Domain\\ComplexityComparison',
    'SeparationResult' => 'Recombinator\\Domain\\SeparationResult',
    'EffectGroup' => 'Recombinator\\Domain\\EffectGroup',
    'EffectBoundary' => 'Recombinator\\Domain\\EffectBoundary',
    'PureComputation' => 'Recombinator\\Domain\\PureComputation',
    'FunctionCandidate' => 'Recombinator\\Domain\\FunctionCandidate',
    'StructureImprovement' => 'Recombinator\\Domain\\StructureImprovement',
    'ScopeStore' => 'Recombinator\\Domain\\ScopeStore',
    'NamingSuggester' => 'Recombinator\\Domain\\NamingSuggester',

    // Support
    'ColorDiffer' => 'Recombinator\\Support\\ColorDiffer',
    'Sandbox' => 'Recombinator\\Support\\Sandbox',
    'SandboxError' => 'Recombinator\\Support\\SandboxError',
];

function updateUseStatements($filePath, $classMap) {
    $content = file_get_contents($filePath);
    $updated = false;

    // Обновляем use statements
    foreach ($classMap as $className => $newFqcn) {
        // Pattern для old use statement: use Recombinator\ClassName;
        $oldPattern = '/^use\s+Recombinator\\\\' . preg_quote($className, '/') . ';/m';
        $newStatement = "use $newFqcn;";

        if (preg_match($oldPattern, $content)) {
            $content = preg_replace($oldPattern, $newStatement, $content);
            $updated = true;
        }

        // Pattern для old use statement с подпространством: use Recombinator\Visitor\ClassName;
        $oldPattern2 = '/^use\s+Recombinator\\\\Visitor\\\\' . preg_quote($className, '/') . ';/m';
        if (preg_match($oldPattern2, $content)) {
            $content = preg_replace($oldPattern2, $newStatement, $content);
            $updated = true;
        }
    }

    if ($updated) {
        file_put_contents($filePath, $content);
        echo "Updated use statements in: $filePath\n";
    }
}

// Рекурсивно обрабатываем все PHP файлы
function processDirectory($dir, $classMap) {
    $files = glob($dir . '/*.php');

    foreach ($files as $file) {
        updateUseStatements($file, $classMap);
    }

    $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
    foreach ($subdirs as $subdir) {
        processDirectory($subdir, $classMap);
    }
}

echo "Updating use statements...\n";
processDirectory($srcDir, $classMap);

echo "\nUse statements update completed!\n";
