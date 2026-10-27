<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\RemoveUnusedMethodVisitor;

function applyRemoveUnusedMethod(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new RemoveUnusedMethodVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('removes methods whose name is never used in live code', function (): void {
    $result = applyRemoveUnusedMethod(
        'class A { public function used() { return $this->helper(); } private function helper() { return 1; }
            public function unused() { return $this->onlyFromDead(); } private function onlyFromDead() { return 2; } }
        echo (new A())->used();',
    );

    expect($result)->toContain('function used')
        ->and($result)->toContain('function helper')
        ->and($result)->not->toContain('function unused')
        ->and($result)->not->toContain('function onlyFromDead');
});

it('keeps methods referenced by strings, callables and prefixes', function (string $use, string $method): void {
    $result = applyRemoveUnusedMethod(
        "class A { public function {$method}() { return 1; } public function other() { return 2; } }
        \$a = new A(); {$use}",
    );

    expect($result)->toContain("function {$method}")
        ->and($result)->not->toContain('function other');
})->with([
    'array callable'   => ['array_map([$a, "run"], [1]);', 'run'],
    'static string'    => ['call_user_func("A::make");', 'make'],
    'slim notation'    => ['$route = "A:handle";', 'handle'],
    'first-class'      => ['$f = $a->go(...);', 'go'],
    'concat prefix'    => ['$m = "get" . $field; $a->$m();', 'getName'],
    'interpolated'     => ['$m = "set{$field}"; $a->$m(1);', 'setName'],
]);

it('keeps magic, attributed and externally declared methods', function (): void {
    $result = applyRemoveUnusedMethod(
        'class A implements IteratorAggregate, Countable {
            public function __toString(): string { return ""; }
            #[PrePersist] public function onPersist() {}
            public function getIterator(): Iterator { return new ArrayIterator([]); }
            public function count(): int { return 0; }
            public function dead() {}
        }
        class B extends Unknown\Base { public function hook() {} }
        new A(); new B();',
    );

    expect($result)->toContain('function __toString')
        ->and($result)->toContain('function onPersist')
        ->and($result)->toContain('function getIterator')
        ->and($result)->toContain('function count')
        ->and($result)->toContain('function hook')
        ->and($result)->not->toContain('function dead');
});

it('keeps an interface method while its name is used', function (): void {
    $result = applyRemoveUnusedMethod(
        'interface I { public function run(); public function stop(); }
        class A implements I { public function run() { return 1; } public function stop() {} }
        function f(I $i) { return $i->run(); } echo f(new A());',
    );

    expect($result)->toContain('public function run();')
        ->and($result)->toContain('public function run()')
        ->and($result)->not->toContain('stop');
});

it('keeps methods called from project files outside the program', function (): void {
    $code = '<?php class Faker_Provider_Base { public static function randomElement() {} public function unused() {} }
        new Faker_Provider_Base();';
    $files = [
        'vendor/faker/Base.php'     => "<?php namespace Faker\\Provider; class Base {}",
        'vendor/faker/Internet.php' => "<?php namespace Faker\\Provider; class Internet extends Base { function email() { return static::randomElement(); } }",
    ];
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new RemoveUnusedMethodVisitor(null, $files))->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    expect($result)->toContain('function randomElement')
        ->and($result)->not->toContain('function unused');
});
