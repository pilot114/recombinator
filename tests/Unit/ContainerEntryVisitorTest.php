<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ContainerEntryVisitor;

const CONTAINER = '<?php final class Box {
    private array $items;
    public function __construct(array $entries = []) { $this->items = $entries; }
    public function get(string $id): mixed {
        if (!$this->resolved($id)) { $entry = call_user_func($this->items[$id], $this); $this->items[$id] = $entry; }
        return $this->items[$id];
    }
    public function has(string $id): bool { return array_key_exists($id, $this->items); }
    public function set(string $id, mixed $entry): void { $this->items[$id] = $entry; }
    public function factory(string $id, Closure $f): void { $this->set($id, $f); }
    public function resolved(string $id): bool {
        if (!$this->has($id)) { throw new LogicException("x"); }
        return !$this->items[$id] instanceof Closure;
    }
}
class Coll { private array $e = []; public function set($k, $v) { $this->e[$k] = $v; } }
';

function applyContainerEntry(string $code, bool $assumeImmutable = false): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(CONTAINER . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new ContainerEntryVisitor($assumeImmutable))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('substitutes literal entries read inside factories', function (): void {
    $result = applyContainerEntry(
        '$cnt = new Box(["settings" => ["dev" => true, "dir" => ROOT . "/x"], "other" => f()]);
        $cnt->set("em", static function ($c) { return [$c->get("settings")["dev"], $c->get("other")]; });
        class Holder { public function __construct(private Coll $coll) {} public function put($k, $v) { $this->coll->set($k, $v); } }
        $cnt->get("em");',
    );

    expect($result)
        ->toContain('return [["dev" => true, "dir" => ROOT . "/x"]["dev"], $c->get("other")];');
});

it('recognizes get() with resolved() already inlined', function (): void {
    // Так get() выглядит после встраивания resolved(): проверка с throw и `!!… instanceof \Closure`
    $container = str_replace(
        'if (!$this->resolved($id)) {',
        'if (!array_key_exists($id, $this->items)) { throw new LogicException("x"); }
        if (!!$this->items[$id] instanceof \Closure) {',
        CONTAINER,
    );
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($container . '$cnt = new Box(["settings" => ["dev" => true]]);
        $cnt->set("em", static function ($c) { return $c->get("settings")["dev"]; });') ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new ContainerEntryVisitor())->traverse($ast);

    expect(new Printer()->prettyPrint($ast))->toContain('return ["dev" => true]["dev"];');
});

it('ignores writers on values that cannot be the container', function (): void {
    $result = applyContainerEntry(
        '$cnt = new Box(["settings" => 1]);
        $cnt->set("em", static function ($c) { return $c->get("settings"); });
        function w($box, $k) { $box->set($k, 1); } w(new Coll(), $key);
        class H { private $item; function __construct() { $this->item = new Coll(); } function put($v) { $this->item->set($v); } }',
    );

    expect($result)->toContain('return 1;');
});

it('keeps entries that may be overwritten', function (string $write): void {
    $result = applyContainerEntry(
        '$cnt = new Box(["settings" => ["dev" => true]]);
        $cnt->set("em", static function ($c) { return $c->get("settings"); }); ' . $write,
    );

    expect($result)->toContain('return $c->get("settings");');
})->with([
    'same key'          => ['$cnt->set("settings", []);'],
    'via factory'       => ['$cnt->factory("settings", fn() => 1);'],
    'computed key'      => ['function w($box, $k) { $box->set($k, 1); } w($cnt, $key);'],
    'dynamic method'    => ['function w($box, $m) { $box->$m("settings", 1); } $x = "set"; w($cnt, $x);'],
    'through property'  => ['class H { private $b; function __construct($b) { $this->b = $b; } function put($k) { $this->b->set($k, 2); } } new H($cnt);'],
]);

it('trusts unresolved writer calls under the containerEntriesImmutable assumption', function (): void {
    $code = '$cnt = new Box(["settings" => 1]);
        $cnt->set("em", static function ($c) { return $c->get("settings"); });
        function w($box, $k) { $box->set($k, 1); } w($cnt, $key);';

    expect(applyContainerEntry($code))->toContain('return $c->get("settings");')
        ->and(applyContainerEntry($code, true))->toContain('return 1;')
        // литеральный ключ учитывается и при допущении
        ->and(applyContainerEntry($code . ' $cnt->set("settings", 2);', true))->toContain('return $c->get("settings");');
});
