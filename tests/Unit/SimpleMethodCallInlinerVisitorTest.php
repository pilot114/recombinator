<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\SimpleMethodCallInlinerVisitor;

function applySimpleMethodCallInliner(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];

    return new Printer()->prettyPrint(new NodeTraverser(new SimpleMethodCallInlinerVisitor())->traverse($ast));
}

it('inlines a single-return method that reads public members', function (): void {
    $code = 'class Cat { public function __construct(public string $name) {} public function describe(): string { return $this->name . " the cat"; } }
$c = new Cat("Tom");
echo $c->describe();';

    expect(applySimpleMethodCallInliner($code))->toContain('echo $c->name . " the cat";');
});

it('keeps the call when the body reads non-public members', function (string $member): void {
    $code = "class Cat { public function __construct(protected int \$age) {} private function secret(): int { return 1; } private const K = 2;
    public function describe(): int { return {$member}; } }
\$c = new Cat(7);
echo \$c->describe();";

    expect(applySimpleMethodCallInliner($code))->toContain('echo $c->describe();');
})->with([
    'protected promoted property' => ['$this->age'],
    'private method'              => ['$this->secret()'],
    'private constant'            => ['self::K'],
]);
