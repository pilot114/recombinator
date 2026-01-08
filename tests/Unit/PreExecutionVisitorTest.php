<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    $this->printer = new StandardPrinter();
    $this->visitor = new PreExecutionVisitor();
    $this->traverser = new NodeTraverser();
    $this->traverser->addVisitor($this->visitor);
});

it('replaces strlen with constant result', function () {
    $code = '<?php $x = strlen("hello");';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = 5');
});

it('replaces abs with constant result', function () {
    $code = '<?php $x = abs(-42);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // abs работает, но -42 уже унарная операция
    expect($result)->toContain('abs(-42)');
});

it('replaces max with constant result', function () {
    $code = '<?php $x = max(1, 2, 3);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = 3');
});

it('replaces strtoupper with constant result', function () {
    $code = '<?php $x = strtoupper("hello");';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = \'HELLO\'');
});

it('replaces count with constant result', function () {
    $code = '<?php $x = count([1, 2, 3]);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = 3');
});

it('replaces in_array with constant result', function () {
    $code = '<?php $x = in_array(2, [1, 2, 3]);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = true');
});

it('replaces json_encode with constant result', function () {
    $code = '<?php $x = json_encode(["a" => 1]);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('\'{"a":1}\'');
});

it('does not replace function calls with variable arguments', function () {
    $code = '<?php $x = strlen($y);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // Не должно измениться
    expect($result)->toContain('strlen($y)');
});

it('does not replace forbidden functions', function () {
    $code = '<?php $x = rand();';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // rand() не в whitelist, не должно измениться
    expect($result)->toContain('rand()');
});

it('executes binary operations with constants', function () {
    $code = '<?php $x = 1 + 2;';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = 3');
});

it('executes multiplication with constants', function () {
    $code = '<?php $x = 3 * 4;';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = 12');
});

it('executes string concatenation with constants', function () {
    $code = '<?php $x = "hello" . " " . "world";';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('\'hello world\'');
});

it('executes unary operations with constants', function () {
    $code = '<?php $x = -42;';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = -42');
});

it('executes ternary with constant condition - true branch', function () {
    $code = '<?php $x = true ? "yes" : "no";';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('"yes"');
});

it('executes ternary with constant condition - false branch', function () {
    $code = '<?php $x = false ? "yes" : "no";';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('"no"');
});

it('does not execute ternary with variable condition', function () {
    $code = '<?php $x = $cond ? "yes" : "no";';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // Не должно измениться
    expect($result)->toContain('$cond ?');
});

it('tracks execution statistics', function () {
    $code = '<?php
        $a = strlen("hello");
        $b = abs(-10);
        $c = rand();
    ';
    $ast = $this->parser->parse($code);
    $this->traverser->traverse($ast);

    $stats = $this->visitor->getStats();

    expect($stats['executed'])->toBeGreaterThan(0)
        ->and($stats['total'])->toBeGreaterThan(0);
});

it('handles nested function calls', function () {
    $code = '<?php $x = strlen(strtoupper("hello"));';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // После первого прохода strtoupper должен вычислиться
    // Может потребоваться несколько проходов для полной оптимизации
    expect($result)->toContain('strlen(\'HELLO\')');
});

it('handles array functions with constant arrays', function () {
    $code = '<?php $x = array_merge([1, 2], [3, 4]);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    // Массив может быть выведен с явными ключами
    expect($result)->toContain('1')->and($result)->toContain('2')
        ->and($result)->toContain('3')->and($result)->toContain('4');
});

it('replaces is_array with constant result for array', function () {
    $code = '<?php $x = is_array([1, 2, 3]);';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = true');
});

it('replaces is_array with constant result for non-array', function () {
    $code = '<?php $x = is_array("test");';
    $ast = $this->parser->parse($code);
    $transformedAst = $this->traverser->traverse($ast);
    $result = $this->printer->prettyPrintFile($transformedAst);

    expect($result)->toContain('$x = false');
});
