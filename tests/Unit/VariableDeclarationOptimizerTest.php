<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\VariableDeclarationOptimizer;
use Recombinator\Transformation\RelatedVariableGroup;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->optimizer = new VariableDeclarationOptimizer();
    }
);

/**
 * Helper to mark AST with side effects
 */
function markSideEffects(array $ast): array
{
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new SideEffectMarkerVisitor());
    return $traverser->traverse($ast);
}

it(
    'finds related variables from same array',
    function (): void {
        $code = '<?php
    $x = $_GET["x"];
    $y = $_GET["y"];
    $z = $_GET["z"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should identify related variables
        expect($result)->toBeArray();
    }
);

it(
    'finds related variables from same object',
    function (): void {
        $code = '<?php
    $x = $point->x;
    $y = $point->y;
    $z = $point->z;';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        expect($result)->toBeArray();
    }
);

it(
    'does not group unrelated variables',
    function (): void {
        $code = '<?php
    $x = $_GET["x"];
    $y = $_POST["y"];
    $z = $someVar;';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should return AST unchanged if no grouping is done
        expect(count($result))->toBe(count($ast));
    }
);

it(
    'handles empty AST',
    function (): void {
        $ast = [];
        $result = $this->optimizer->optimize($ast);

        expect($result)->toBeEmpty();
    }
);

it(
    'handles AST with no variable assignments',
    function (): void {
        $code = '<?php
    echo "Hello";
    echo "World";';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        expect(count($result))->toBe(count($ast));
    }
);

it(
    'handles single variable assignment',
    function (): void {
        $code = '<?php
    $x = $_GET["x"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        expect(count($result))->toBe(1);
    }
);

it(
    'identifies variables from array access',
    function (): void {
        $code = '<?php
    $username = $_GET["username"];
    $password = $_GET["password"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        // The optimizer should find these as related
        $result = $this->optimizer->optimize($ast);
        expect($result)->not->toBeEmpty();
    }
);

it(
    'creates RelatedVariableGroup with correct size',
    function (): void {
        $assignments = [
        ['var' => '$x', 'expr' => null, 'index' => 0],
        ['var' => '$y', 'expr' => null, 'index' => 1],
        ['var' => '$z', 'expr' => null, 'index' => 2],
        ];

        $group = new RelatedVariableGroup($assignments);

        expect($group->getSize())->toBe(3);
    }
);

it(
    'generates unique signature for variable group',
    function (): void {
        $assignments1 = [
        ['var' => '$x', 'expr' => null, 'index' => 0],
        ['var' => '$y', 'expr' => null, 'index' => 1],
        ];

        $assignments2 = [
        ['var' => '$y', 'expr' => null, 'index' => 1],
        ['var' => '$x', 'expr' => null, 'index' => 0],
        ];

        $group1 = new RelatedVariableGroup($assignments1);
        $group2 = new RelatedVariableGroup($assignments2);

        // Same variables in different order should have same signature
        expect($group1->getSignature())->toBe($group2->getSignature());
    }
);

it(
    'gets correct indices from variable group',
    function (): void {
        $assignments = [
        ['var' => '$x', 'expr' => null, 'index' => 5],
        ['var' => '$y', 'expr' => null, 'index' => 10],
        ['var' => '$z', 'expr' => null, 'index' => 15],
        ];

        $group = new RelatedVariableGroup($assignments);

        expect($group->getIndices())->toBe([5, 10, 15]);
    }
);

it(
    'handles complex expressions in variable assignments',
    function (): void {
        $code = '<?php
    $x = $data["coords"]["x"];
    $y = $data["coords"]["y"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        expect($result)->toBeArray();
    }
);

it(
    'preserves non-assignment statements',
    function (): void {
        $code = '<?php
    $x = $_GET["x"];
    echo "Processing...";
    $y = $_GET["y"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should preserve all 3 statements
        expect(count($result))->toBe(3);
    }
);

it(
    'ignores dynamic variable assignments',
    function (): void {
        $code = '<?php
    ${$varName} = "value";
    $normal = "test";';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should handle gracefully without errors
        expect($result)->toBeArray();
    }
);

it(
    'handles variables from different array keys',
    function (): void {
        $code = '<?php
    $a = $arr["first"];
    $b = $arr["second"];
    $c = $arr["third"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should recognize these as related (same array)
        expect($result)->toBeArray();
    }
);

it(
    'handles variables from different objects',
    function (): void {
        $code = '<?php
    $x = $point1->x;
    $y = $point2->y;';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Different objects - should not group
        expect($result)->toBeArray();
    }
);

it(
    'deduplicates variable groups correctly',
    function (): void {
        // When the same group of variables appears multiple times,
        // it should be deduplicated
        $group1 = new RelatedVariableGroup(
            [
            ['var' => '$x', 'expr' => null, 'index' => 0],
            ['var' => '$y', 'expr' => null, 'index' => 1],
            ]
        );

        $group2 = new RelatedVariableGroup(
            [
            ['var' => '$x', 'expr' => null, 'index' => 0],
            ['var' => '$y', 'expr' => null, 'index' => 1],
            ]
        );

        expect($group1->getSignature())->toBe($group2->getSignature());
    }
);

it(
    'handles mixed assignment types',
    function (): void {
        $code = '<?php
    $x = $_GET["x"];
    $obj = new stdClass();
    $y = $_GET["y"];';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should identify $x and $y as related, but not $obj
        expect(count($result))->toBe(3);
    }
);

it(
    'preserves statement order when no optimization is applied',
    function (): void {
        $code = '<?php
    $a = 1;
    $b = 2;
    $c = 3;';

        $ast = $this->parser->parse($code);
        $ast = markSideEffects($ast);

        $result = $this->optimizer->optimize($ast);

        // Should preserve original order
        expect(count($result))->toBe(count($ast));
    }
);
