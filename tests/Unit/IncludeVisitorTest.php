<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use Recombinator\Support\IncludeException;
use Recombinator\Transformation\Visitor\IncludeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

it(
    'throws IncludeException for non-existent file',
    function (): void {
        $code = '<?php include "nonexistent_file.php";';
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeConnectingVisitor());

        $ast = $traverser->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new IncludeVisitor('/tmp/test_entry.php'));

        expect(fn(): array => $traverser->traverse($ast))
            ->toThrow(IncludeException::class, 'does not exist');
    }
);

it(
    'throws IncludeException for path traversal attempt',
    function (): void {
        // Create a temporary file that exists but is outside the base directory
        $tmpDir = sys_get_temp_dir() . '/recombinator_test_' . uniqid();
        mkdir($tmpDir);
        $subDir = $tmpDir . '/sub';
        mkdir($subDir);
        $targetFile = $tmpDir . '/secret.php';
        file_put_contents($targetFile, '<?php echo "secret";');
        $entryPoint = $subDir . '/entry.php';
        file_put_contents($entryPoint, '<?php echo "entry";');

        $code = '<?php include "../secret.php";';
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeConnectingVisitor());

        $ast = $traverser->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new IncludeVisitor($entryPoint));

        expect(fn(): array => $traverser->traverse($ast))
            ->toThrow(IncludeException::class, 'Path traversal');

        // Cleanup
        unlink($targetFile);
        unlink($entryPoint);
        rmdir($subDir);
        rmdir($tmpDir);
    }
);

it(
    'resolves valid include path correctly',
    function (): void {
        $tmpDir = sys_get_temp_dir() . '/recombinator_test_' . uniqid();
        mkdir($tmpDir);
        $entryPoint = $tmpDir . '/entry.php';
        file_put_contents($entryPoint, '<?php echo "entry";');
        $includeFile = $tmpDir . '/included.php';
        file_put_contents($includeFile, '<?php $x = 42;');

        $code = '<?php include "included.php";';
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeConnectingVisitor());

        $ast = $traverser->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new IncludeVisitor($entryPoint));

        // Should not throw
        $result = $traverser->traverse($ast);

        expect($result)->toBeArray();

        // Cleanup
        unlink($entryPoint);
        unlink($includeFile);
        rmdir($tmpDir);
    }
);
