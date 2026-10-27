<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Transformation\Visitor\InvariantPropertyVisitor;

it('substitutes invariant property reads on receivers of the class', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Meta { public $inheritanceType = 1; public $associationMappings = [];
            public function isJoined() { return $this->inheritanceType === 3; }
            public function map($m) { $this->associationMappings[$m] = 1; } }
        class Persister { public function __construct(private Meta $class) {}
            public function run(Meta $c) { foreach ($c->associationMappings as $a) {} unset($c->inheritanceType); return [$this->class->inheritanceType, isset($c->inheritanceType)]; } }
        function other($x) { return $x->inheritanceType; }',
    ) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);

    $env = TargetEnvironment::fromArray(['invariants' => ['Meta' => ['inheritanceType' => 1, 'associationMappings' => []]]]);
    $result = new Printer()->prettyPrint(new NodeTraverser(new InvariantPropertyVisitor($env))->traverse($ast));

    expect($result)->toContain('return 1 === 3;')
        ->toContain('$this->associationMappings[$m] = 1;')
        ->toContain('foreach ([] as $a)')
        ->toContain('unset($c->inheritanceType);')
        ->toContain('return [1, true];')
        ->toContain('return $x->inheritanceType;');
});

it('folds isset and empty over invariant properties', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Meta { public $associationMappings = []; public $versionField = null;
            public function isCollection($f) { return isset($this->associationMappings[$f]) && $this->x; }
            public function versioned() { return [isset($this->versionField), empty($this->associationMappings)]; } }',
    ) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);

    $env = TargetEnvironment::fromArray(['invariants' => ['Meta' => ['associationMappings' => [], 'versionField' => null]]]);
    $result = new Printer()->prettyPrint(new NodeTraverser(new InvariantPropertyVisitor($env))->traverse($ast));

    expect($result)->toContain('return false && $this->x;')
        ->toContain('return [false, empty($this->associationMappings)];');
});
