<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Recombinator\Support\StringValues;

it('computes the finite set of class names built at runtime', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php
        function create($locale = "en_US") { return [provider("Address", $locale), provider("Person", $locale)]; }
        function provider($provider, $locale = "") { return find($provider, $locale) ?: find($provider); }
        function find($provider, $locale = "") {
            $name = $locale ? sprintf("Provider\\\\%s\\\\%s", $locale, $provider) : sprintf("Provider\\\\%s", $provider);
            $class = "Faker\\\\{$name}";
            return class_exists($class) ? $class : null;
        }
        create();') ?? [];
    $ast  = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    $call = new NodeFinder()->findFirst($ast, static fn(Node $n): bool => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && $n->name->toString() === 'class_exists');
    assert($call instanceof Node\Expr\FuncCall);

    // Условие тернарного оператора не связывается с ветками: лишние сочетания безвредны
    expect(new StringValues($ast)->of($call->getArgs()[0]->value))
        ->toContain('Faker\Provider\Address', 'Faker\Provider\Person', 'Faker\Provider\en_US\Address', 'Faker\Provider\en_US\Person');
});

it('gives up on values coming from data', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php function f($x) { return class_exists("App\\\\" . $x); } f($_GET["c"]);') ?? [];
    $ast  = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    $call = new NodeFinder()->findFirst($ast, static fn(Node $n): bool => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && $n->name->toString() === 'class_exists');
    assert($call instanceof Node\Expr\FuncCall);

    expect(new StringValues($ast)->of($call->getArgs()[0]->value))->toBeNull();
});
