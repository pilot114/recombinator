<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Transformation\Visitor\EvalStandardFunction;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
        $this->visitor = new EvalStandardFunction();
    }
);

function transformWithEval(array $ast, EvalStandardFunction $visitor): array
{
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    return $traverser->traverse($ast);
}

it(
    'evaluates is_array on array literal to true',
    function (): void {
        $code = '<?php is_array([1, 2, 3]);';
        $ast = $this->parser->parse($code);

        $result = transformWithEval($ast, $this->visitor);
        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('true');
    }
);

it(
    'evaluates is_array on non-array to false',
    function (): void {
        $code = '<?php is_array("string");';
        $ast = $this->parser->parse($code);

        $result = transformWithEval($ast, $this->visitor);
        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('false');
    }
);

it(
    'evaluates is_array on variable to false (static analysis)',
    function (): void {
        $code = '<?php is_array($var);';
        $ast = $this->parser->parse($code);

        $result = transformWithEval($ast, $this->visitor);
        $output = $this->printer->prettyPrint($result);

        // isArrayHandler replaces non-Array_ nodes with false
        expect($output)->toContain('false');
    }
);

it(
    'handles in_array with static arguments',
    function (): void {
        $code = '<?php in_array("a", ["a", "b", "c"]);';
        $ast = $this->parser->parse($code);

        // in_array with static args - visitor currently returns null (no transform)
        $result = transformWithEval($ast, $this->visitor);
        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('in_array');
    }
);
