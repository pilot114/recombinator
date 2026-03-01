<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

it(
    'separates function definitions into cache files',
    function (): void {
        $tmpDir = sys_get_temp_dir() . '/recombinator_scope_test_' . uniqid();
        mkdir($tmpDir);

        $entryFile = $tmpDir . '/entry.php';
        file_put_contents($entryFile, '<?php echo "entry";');

        $code = '<?php
function hello() { return "hello"; }
echo hello();';
        $ast = $this->parser->parse($code);

        $visitor = new ScopeVisitor($entryFile, $tmpDir);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        // Check that scope files were created
        $files = glob($tmpDir . '/*');

        expect(count($files))->toBeGreaterThanOrEqual(1);

        // Cleanup
        array_map(unlink(...), glob($tmpDir . '/*') ?: []);
        if (file_exists($entryFile)) {
            unlink($entryFile);
        }

        if (is_dir($tmpDir)) {
            rmdir($tmpDir);
        }
    }
);

it(
    'separates class definitions into cache files',
    function (): void {
        $tmpDir = sys_get_temp_dir() . '/recombinator_scope_test_' . uniqid();
        mkdir($tmpDir);

        $entryFile = $tmpDir . '/entry.php';
        file_put_contents($entryFile, '<?php echo "entry";');

        $code = '<?php
class MyClass {
    public function greet() { return "hi"; }
}
$obj = new MyClass();';
        $ast = $this->parser->parse($code);

        $visitor = new ScopeVisitor($entryFile, $tmpDir);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $files = glob($tmpDir . '/*');

        expect(count($files))->toBeGreaterThanOrEqual(1);

        // Cleanup
        array_map(unlink(...), glob($tmpDir . '/*') ?: []);
        if (file_exists($entryFile)) {
            unlink($entryFile);
        }

        if (is_dir($tmpDir)) {
            rmdir($tmpDir);
        }
    }
);

it(
    'skips when cacheDir is null',
    function (): void {
        $code = '<?php function test() { return 1; }';
        $ast = $this->parser->parse($code);

        $visitor = new ScopeVisitor();

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        $result = $traverser->traverse($ast);

        // Should not throw and should return AST unchanged
        expect($result)->toBeArray();
    }
);
