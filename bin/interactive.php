#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Третий этап: интерактивная правка того, что не автоматизируется (имена переменных,
 * магические числа, вложенность). На вход — итог конвейера (результат в веб-интерфейсе,
 * examples/08-syntax-zoo/result.php после bin/zoo.php) или любой PHP-файл.
 *
 *   php bin/interactive.php result.php    → list, apply <n> [name], undo, save <path>
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use Recombinator\Interactive\InteractiveCLI;
use Recombinator\Interactive\InteractiveEditAnalyzer;

$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/interactive.php <file.php>\n");
    exit(1);
}

$ast = new ParserFactory()->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
new InteractiveCLI($ast, new InteractiveEditAnalyzer()->analyze($ast))->run();
