<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->store = new ScopeStore();
    }
);

it(
    'can process large number of binary operations efficiently',
    function (): void {
        // Generate code with 1000 binary operations
        $operations = [];
        for ($i = 1; $i <= 1000; $i++) {
            $operations[] = sprintf('$var%d = ', $i) . ($i * 2) . " + " . ($i * 3) . ";";
        }

        $code = "<?php\n" . implode("\n", $operations);

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor(new BinaryAndIssetVisitor());
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 1 second
        expect($duration)->toBeLessThan(1.0);
    }
)->group('performance');

it(
    'can process large number of constants efficiently',
    function (): void {
        // Generate code with 500 constants
        $constants = ["<?php\nclass BigConfig {"];
        for ($i = 1; $i <= 500; $i++) {
            $constants[] = sprintf('    const CONST_%d = %d;', $i, $i);
        }

        $constants[] = "}";

        $code = implode("\n", $constants);

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor(new ConstClassVisitor($this->store));
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 1 second
        expect($duration)->toBeLessThan(1.0);
    }
)->group('performance');

it(
    'can process large number of variable assignments efficiently',
    function (): void {
        // Generate code with 1000 variable assignments
        $assignments = ["<?php"];
        for ($i = 1; $i <= 1000; $i++) {
            $assignments[] = sprintf('$var%d = %d;', $i, $i);
            $assignments[] = sprintf('echo $var%d;', $i);
        }

        $code = implode("\n", $assignments);

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor(new VarToScalarVisitor($this->store));
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 2 seconds
        expect($duration)->toBeLessThan(2.0);
    }
)->group('performance');

it(
    'can process deeply nested expressions efficiently',
    function (): void {
        // Generate deeply nested expression: 1 + (2 + (3 + (4 + ...)))
        $expression = "1";
        for ($i = 2; $i <= 100; $i++) {
            $expression = sprintf('%d + (%s)', $i, $expression);
        }

        $code = sprintf('<?php $result = %s;', $expression);

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new BinaryAndIssetVisitor());
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 1 second
        expect($duration)->toBeLessThan(1.0);
    }
)->group('performance');

it(
    'can process multiple visitors in pipeline efficiently',
    function (): void {
        // Generate complex code
        $code = "<?php\n";
        $code .= "class Config {\n";
        for ($i = 1; $i <= 100; $i++) {
            $code .= "    const VAL_{$i} = {$i};\n";
        }

        $code .= "}\n\n";

        for ($i = 1; $i <= 100; $i++) {
            $code .= sprintf('$x%d = Config::VAL_%d + ', $i, $i) . ($i * 2) . ";\n";
            $code .= "echo \$x{$i};\n";
        }

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor(new ConstClassVisitor($this->store));
        $traverser->addVisitor(new BinaryAndIssetVisitor());
        $traverser->addVisitor(new VarToScalarVisitor($this->store));
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 2 seconds
        expect($duration)->toBeLessThan(2.0);
    }
)->group('performance');

it(
    'has linear time complexity for variable replacement',
    function (): void {
        $sizes = [100, 200, 400];
        $times = [];

        foreach ($sizes as $size) {
            $assignments = ["<?php"];
            for ($i = 1; $i <= $size; $i++) {
                $assignments[] = sprintf('$var%d = %d;', $i, $i);
                $assignments[] = sprintf('echo $var%d;', $i);
            }

            $code = implode("\n", $assignments);

            $ast = $this->parser->parse($code);
            $store = new ScopeStore();

            $start = microtime(true);

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ScopeVisitor());
            $traverser->addVisitor(new VarToScalarVisitor($store));
            $traverser->traverse($ast);

            $times[$size] = microtime(true) - $start;
        }

        // Time should grow roughly linearly, not exponentially
        // 400 items should not take more than 5x the time of 100 items
        expect($times[400])->toBeLessThan($times[100] * 5);
    }
)->group('performance');

it(
    'measures memory usage for large code processing',
    function (): void {
        $memoryBefore = memory_get_usage();

        // Generate large code
        $assignments = ["<?php"];
        for ($i = 1; $i <= 5000; $i++) {
            $assignments[] = sprintf('$var%d = %d;', $i, $i);
        }

        $code = implode("\n", $assignments);

        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor(new VarToScalarVisitor($this->store));
        $traverser->traverse($ast);

        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

        // Should use less than 50MB for processing
        expect($memoryUsed)->toBeLessThan(50);
    }
)->group('performance');

it(
    'can handle string concatenation performance',
    function (): void {
        // Generate code with 500 string concatenations
        $parts = ["<?php\n\$result = "];
        for ($i = 1; $i <= 500; $i++) {
            $parts[] = sprintf('"str%d"', $i);
            if ($i < 500) {
                $parts[] = " . ";
            }
        }

        $parts[] = ";";
        $code = implode("", $parts);

        $ast = $this->parser->parse($code);

        $start = microtime(true);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new BinaryAndIssetVisitor());
        $traverser->traverse($ast);

        $duration = microtime(true) - $start;

        // Should complete in less than 2 seconds
        expect($duration)->toBeLessThan(2.0);
    }
)->group('performance');
