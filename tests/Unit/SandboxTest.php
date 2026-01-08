<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\Sandbox;

beforeEach(function () {
    $this->cache = new ExecutionCache();
    $this->sandbox = new Sandbox($this->cache);
    $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
});

it('can execute simple scalar values', function () {
    $code = '<?php 42;';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr; // Получаем выражение

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(42);
});

it('can execute string values', function () {
    $code = '<?php "hello";';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe('hello');
});

it('can execute pure functions with constant arguments', function () {
    $code = '<?php strlen("hello");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(5);
});

it('can execute mathematical functions', function () {
    $code = '<?php abs(-42);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(42);
});

it('can execute array functions', function () {
    $code = '<?php count([1, 2, 3]);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(3);
});

it('can execute string functions', function () {
    $code = '<?php strtoupper("hello");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe('HELLO');
});

it('can execute explode function', function () {
    $code = '<?php explode(",", "a,b,c");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(['a', 'b', 'c']);
});

it('blocks forbidden functions like eval', function () {
    $code = '<?php eval("return 42;");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBeNull();
});

it('blocks forbidden functions like exec', function () {
    $code = '<?php exec("ls");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBeNull();
});

it('blocks forbidden functions like file_get_contents', function () {
    $code = '<?php file_get_contents("/etc/passwd");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBeNull();
});

it('caches execution results', function () {
    $code = '<?php strlen("hello");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    // Первое выполнение
    $result1 = $this->sandbox->execute($node);
    $stats1 = $this->sandbox->getCacheStats();

    // Второе выполнение - должно взять из кеша
    $result2 = $this->sandbox->execute($node);
    $stats2 = $this->sandbox->getCacheStats();

    expect($result1)->toBe(5)
        ->and($result2)->toBe(5)
        ->and($stats2['hits'])->toBeGreaterThan($stats1['hits']);
});

it('can execute with context variables', function () {
    $code = '<?php strlen($str);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node, ['str' => 'hello world']);

    expect($result)->toBe(11);
});

it('can execute max function', function () {
    $code = '<?php max(1, 2, 3);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(3);
});

it('can execute min function', function () {
    $code = '<?php min(1, 2, 3);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(1);
});

it('can execute in_array function', function () {
    $code = '<?php in_array(2, [1, 2, 3]);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBeTrue();
});

it('can execute json_encode function', function () {
    $code = '<?php json_encode(["a" => 1, "b" => 2]);';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe('{"a":1,"b":2}');
});

it('can execute base64_encode function', function () {
    $code = '<?php base64_encode("hello");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBe(base64_encode('hello'));
});

it('detects errors correctly', function () {
    $code = '<?php eval("return 42;");';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    // eval запрещен, поэтому результат будет null
    expect($result)->toBeNull();
});

it('blocks functions not in whitelist', function () {
    // rand() не в whitelist (не детерминирована)
    $code = '<?php rand();';
    $ast = $this->parser->parse($code);
    $node = $ast[0]->expr;

    $result = $this->sandbox->execute($node);

    expect($result)->toBeNull();
});
