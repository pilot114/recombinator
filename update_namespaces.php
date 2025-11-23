#!/usr/bin/env php
<?php

// Скрипт для обновления namespace во всех файлах после реорганизации

$srcDir = __DIR__ . '/src';

// Mapping директорий к namespace
$namespaceMap = [
    '/Core' => 'Recombinator\\Core',
    '/Analysis' => 'Recombinator\\Analysis',
    '/Transformation' => 'Recombinator\\Transformation',
    '/Transformation/Visitor' => 'Recombinator\\Transformation\\Visitor',
    '/Interactive' => 'Recombinator\\Interactive',
    '/Domain' => 'Recombinator\\Domain',
    '/Support' => 'Recombinator\\Support',
];

function updateNamespaceInFile($filePath, $newNamespace) {
    $content = file_get_contents($filePath);

    // Обновляем namespace declaration
    $pattern = '/^namespace\s+Recombinator(\\\\[^;]+)?;/m';
    $replacement = "namespace $newNamespace;";
    $content = preg_replace($pattern, $replacement, $content);

    file_put_contents($filePath, $content);
    echo "Updated namespace in: $filePath\n";
}

function processDirectory($dir, $namespace) {
    $files = glob($dir . '/*.php');

    foreach ($files as $file) {
        updateNamespaceInFile($file, $namespace);
    }

    // Рекурсивно обрабатываем поддиректории
    $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
    foreach ($subdirs as $subdir) {
        $dirname = basename($subdir);
        $newNamespace = $namespace . '\\' . $dirname;
        processDirectory($subdir, $newNamespace);
    }
}

// Обрабатываем каждую директорию
foreach ($namespaceMap as $dir => $namespace) {
    $fullPath = $srcDir . $dir;
    if (is_dir($fullPath)) {
        echo "Processing $fullPath with namespace $namespace\n";

        // Обрабатываем файлы в директории
        $files = glob($fullPath . '/*.php');
        foreach ($files as $file) {
            updateNamespaceInFile($file, $namespace);
        }
    }
}

echo "\nNamespace update completed!\n";
