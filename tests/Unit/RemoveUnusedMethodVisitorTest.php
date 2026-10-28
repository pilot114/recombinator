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
        new Faker_Provider_Base(); $provider = "Faker\\\\Provider\\\\" . $name;';
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

it('resolves calls by receiver type instead of method name', function (): void {
    $result = applyRemoveUnusedMethod(
        'interface Repo { public function find(); }
        class UserRepo implements Repo { public function find() { return $this->load(); } private function load() { return 1; } public function query() { return 2; } }
        class Builder { public function query() { return new Parser(); } }
        class Parser {}
        class Service { public function __construct(private Repo $repo) {} public function run() { return $this->repo->find(); } }
        echo (new Service(new UserRepo()))->run();',
    );

    expect($result)->toContain('public function find();')
        ->toContain('public function find()')
        ->toContain('private function load()')
        ->not->toContain('function query()')
        ->toContain('public function run()');
});

it('keeps implementations while their interface method is alive', function (): void {
    $result = applyRemoveUnusedMethod(
        'interface I { public function m(); } class A implements I { public function m() { return 1; } } class B implements I { public function m() { return 2; } }
        function f(I $i) { return $i->m(); } echo f(new A());',
    );

    expect($result)->toContain('return 1;')->toContain('return 2;');
});

it('infers receiver types of locals and call chains', function (): void {
    $result = applyRemoveUnusedMethod(
        'class Em { public function repo(): Repo { return new Repo(); } public function query() { return 1; } }
        class Repo { public function all() { return []; } public function query() { return 2; } }
        class Qb { public function query() { return 3; } }
        function run(Em $em) { $r = $em->repo(); return $r->all(); }
        echo run(new Em());',
    );

    expect($result)->toContain('function repo()')
        ->toContain('function all()')
        ->not->toContain('function query()');
});

it('narrows receiver types by instanceof checks and guards', function (): void {
    $result = applyRemoveUnusedMethod(
        'class Uri { public function getQuery() { return ""; } }
        class Builder { public function getQuery() { return new Parser(); } }
        class Parser {}
        class Request { public function __construct($uri) { if (!$uri instanceof Uri) { $uri = new Uri(); } parse_str($uri->getQuery(), $q); } }
        function show($x) { return $x instanceof Uri ? $x->getQuery() : ""; }
        new Request("/"); echo show(1);',
    );

    expect($result)->toContain('return "";')
        ->not->toContain('return new Parser();');
});

it('resolves types named by removed interfaces through formerTypes', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Em { public function createQuery() { return 1; } }
        class Qb { public function createQuery() { return 2; } }
        class Persister { public function __construct(private mixed $em) {} public function run() { return $this->em->createQuery(); } }
        echo (new Persister(new Em()))->run();',
    ) ?? [];
    $finder = new PhpParser\NodeFinder();
    foreach ($finder->findInstanceOf($ast, PhpParser\Node\Stmt\Class_::class) as $class) {
        if ((string) $class->name === 'Em') {
            Recombinator\Support\TypeInference::addFormerTypes($class, ['eminterface']);
        }
    }

    foreach ($finder->findInstanceOf($ast, PhpParser\Node\Param::class) as $param) {
        $param->setAttribute('originalType', new PhpParser\Node\Name('EmInterface'));
    }

    $ast    = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast    = new NodeTraverser(new RemoveUnusedMethodVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    expect($result)->toContain('return 1;')->not->toContain('return 2;');
});

it('does not treat strings that never name a method as prefixes', function (): void {
    $result = applyRemoveUnusedMethod(
        'class A { public function evict() { return 1; } public function trim() { return 2; } public function getName() { return 3; }
            public function sql($n) { $alias = "t" . $n; $e = "e{$n}"; echo "FROM x " . $alias, sprintf("%s", $e . "x");
                $m = "get" . $n; return $this->$m(); } }
        (new A())->sql(1);',
    );

    expect($result)->not->toContain('function evict')->not->toContain('function trim')->toContain('function getName');
});

it('follows prefixed strings into parameters of known callees', function (): void {
    $result = applyRemoveUnusedMethod(
        'class A { public function enable() { return 1; } public function getName() { return 2; }
            public function run($n) { $alias = "e" . $n; $this->add($alias); $m = "get" . $n; return $this->call($m); }
            private function add($alias) { $this->map[$alias] = 1; echo $alias . ".x"; }
            private function call($method) { return $this->$method(); } }
        (new A())->run(1);',
    );

    expect($result)->not->toContain('function enable')->toContain('function getName');
});

it('treats a literal followed by a number as a numeric name prefix only', function (): void {
    $result = applyRemoveUnusedMethod(
        'class A { public function evict() { return 1; } public function e1() { return 2; }
            public function run() { $alias = "e" . $this->counter++; return $this->$alias(); } }
        (new A())->run();',
    );

    expect($result)->not->toContain('function evict')->toContain('function e1');
});

it('keeps all methods of classes created by runtime-built names', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Faker_Provider_Base { public function numerify() {} } class Faker_Provider_Person extends Faker_Provider_Base { public function userName() {} }
        class Other { public function unused() {} }
        function make($p) { return new (sprintf("Faker\\\\Provider\\\\%s", $p))(); } make("Person");',
    ) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new RemoveUnusedMethodVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    expect($result)->toContain('function userName')->toContain('function numerify')->not->toContain('function unused');
});

it('removes __call that only accepts prefixes no call can reach', function (): void {
    $result = applyRemoveUnusedMethod(
        'class Repo { public function findAll() { return []; }
            public function __call(string $method, array $args) { if (str_starts_with($method, "findBy")) { return $this->magic($method); } throw new \BadMethodCallException($method); }
            private function magic($m) { return $m; } }
        class Gen { public function __call($m, $a) { return $m; } }
        $r = new Repo(); $r->findAll(); $g = new Gen(); $g->email(); $x->other();',
    );

    expect($result)->not->toContain('function magic')->toContain('class Gen')->toContain('public function __call($m, $a)');
});
