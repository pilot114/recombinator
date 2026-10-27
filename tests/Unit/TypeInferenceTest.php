<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Recombinator\Support\TypeInference;

/** Типы выражения `$probe` в первом `return $probe...;` программы. */
function inferProbeType(string $code): ?array
{
    $ast     = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast     = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    $classes = [];
    foreach (new NodeFinder()->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
        if ($class->name instanceof Node\Identifier) {
            $classes[$class->name->toLowerString()] = $class;
        }
    }

    $probe = new NodeFinder()->findFirst($ast, static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === 'probe');
    assert($probe instanceof Node\Expr\Variable);
    $types = new TypeInference($classes, $ast)->typeOf($probe);
    if ($types !== null) {
        sort($types);
    }

    return $types;
}

it('narrows intersection types to classes implementing every part', function (): void {
    $types = inferProbeType('<?php interface Coll {} interface Sel {}
        class ArrayColl implements Coll, Sel {} class Lazy implements Coll {} class Crit implements Sel {}
        function f(Coll&Sel $probe) { return $probe; }');

    expect($types)->toBe(['arraycoll']);
});

it('ignores external parts of an intersection type', function (): void {
    $types = inferProbeType('<?php interface Coll {}
        class A implements Coll, \Countable {} class B implements Coll, \Countable {}
        function f(Coll&\Countable $probe) { return $probe; }');

    expect($types)->toBe(['a', 'b']);
});

it('infers new self, new static and new parent from the enclosing class', function (): void {
    expect(inferProbeType('<?php class Base {} class Child extends Base { public static function make() { $probe = new static(); return $probe; } }'))
        ->toBe(['child'])
        ->and(inferProbeType('<?php class Base {} class Child extends Base { public function make() { $probe = new parent(); return $probe; } }'))
        ->toBe(['base']);
});
