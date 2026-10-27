<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\UnwrittenArrayKeyVisitor;

function applyUnwrittenArrayKey(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new UnwrittenArrayKeyVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('folds reads of array keys that are never written', function (): void {
    $result = applyUnwrittenArrayKey(
        'class Config { private array $attributes = ["seeded" => 1];
            public function setDir($d) { $this->attributes["dir"] = $d; }
            public function dir() { return $this->attributes["dir"] ?? null; }
            public function lazy() { return $this->attributes["lazy"] ?? false; }
            public function seeded() { return isset($this->attributes["seeded"]); }
            public function cache() { return [isset($this->attributes["cache"]), empty($this->attributes["cache"])]; } }',
    );

    expect($result)->toContain('return $this->attributes["dir"] ?? null;')
        ->toContain('return false;')
        ->toContain('return isset($this->attributes["seeded"]);')
        ->toContain('return [false, true];');
});

it('does not fold when the property is written dynamically or belongs to an outside object', function (): void {
    $result = applyUnwrittenArrayKey(
        'class A { private array $map = []; public function set($k, $v) { $this->map[$k] = $v; } public function get() { return $this->map["x"] ?? 0; } }
        class B { private array $opts = []; public function x(\DOMNode $n) { return [$this->opts["y"] ?? 1, $n->attributes["id"] ?? 2]; } }',
    );

    expect($result)->toContain('return $this->map["x"] ?? 0;')
        ->toContain('return [1, $n->attributes["id"] ?? 2];');
});
