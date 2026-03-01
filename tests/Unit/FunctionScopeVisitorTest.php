<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->store = new ScopeStore();
    }
);

it(
    'stores function in ScopeStore and removes definition',
    function (): void {
        // FunctionScopeVisitor only processes when not global scope (single-node AST)
        $code = '<?php function add($a, $b) { return $a + $b; }';
        $ast = $this->parser->parse($code);

        $tmpDir = sys_get_temp_dir() . '/recombinator_test_' . uniqid();
        mkdir($tmpDir);
        $scopeFile = $tmpDir . '/test_scope';
        file_put_contents($scopeFile, '');

        $visitor = new FunctionScopeVisitor($this->store, $tmpDir);
        $visitor->scopeName = 'test_scope';

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $body = $this->store->getFunctionFromGlobal('add');

        expect($body)->not->toBeNull();

        // Cleanup
        if (file_exists($scopeFile)) {
            unlink($scopeFile);
        }

        rmdir($tmpDir);
    }
);

it(
    'ignores functions with multiple statements',
    function (): void {
        $code = '<?php function complex($x) { $y = $x * 2; return $y; }';
        $ast = $this->parser->parse($code);

        $tmpDir = sys_get_temp_dir() . '/recombinator_test_' . uniqid();
        mkdir($tmpDir);

        $visitor = new FunctionScopeVisitor($this->store, $tmpDir);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $body = $this->store->getFunctionFromGlobal('complex');

        expect($body)->toBeNull();

        // Cleanup
        rmdir($tmpDir);
    }
);
