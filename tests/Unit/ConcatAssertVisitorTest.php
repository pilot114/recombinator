<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
    }
);

function transformWithConcatAssert(array $ast): array
{
    // First connect nodes (needed for 'next' attribute)
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NodeConnectingVisitor());

    $ast = $traverser->traverse($ast);

    // Then apply visitor
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ConcatAssertVisitor());
    return $traverser->traverse($ast);
}

it(
    'merges two consecutive echo statements',
    function (): void {
        $code = '<?php echo "Hello"; echo " World";';
        $ast = $this->parser->parse($code);

        $result = transformWithConcatAssert($ast);
        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('.');
        // Should be a single echo with concatenation
        expect(substr_count((string) $output, 'echo'))->toBe(1);
    }
);

it(
    'does not merge echo separated by other statements',
    function (): void {
        $code = '<?php echo "Hello"; $x = 1; echo " World";';
        $ast = $this->parser->parse($code);

        $result = transformWithConcatAssert($ast);
        $output = $this->printer->prettyPrint($result);

        // Should remain as separate echo statements
        expect(substr_count((string) $output, 'echo'))->toBe(2);
    }
);

it(
    'merges three or more consecutive echo',
    function (): void {
        $code = '<?php echo "A"; echo "B"; echo "C";';
        $ast = $this->parser->parse($code);

        $result = transformWithConcatAssert($ast);
        $output = $this->printer->prettyPrint($result);

        // Should result in a single echo
        expect(substr_count((string) $output, 'echo'))->toBe(1);
    }
);

it(
    'handles single echo without changes',
    function (): void {
        $code = '<?php echo "Hello";';
        $ast = $this->parser->parse($code);

        $result = transformWithConcatAssert($ast);
        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('echo');
        expect(substr_count((string) $output, 'echo'))->toBe(1);
    }
);
