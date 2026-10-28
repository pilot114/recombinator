<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\InterfaceTypeCheckVisitor;

function applyInterfaceTypeCheck(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new InterfaceTypeCheckVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('replaces interface type checks with checks of its implementations', function (): void {
    $result = applyInterfaceTypeCheck(
        'interface Coll {} interface Fail extends \Throwable {} interface Never {}
        class ArrayColl implements Coll {} class Lazy extends ArrayColl {} class Persistent implements Coll {}
        class DbError extends \Exception implements Fail {}
        function f(?Coll $c, Coll|int $d): Coll { try { return $c; } catch (Fail $e) { return $c instanceof Coll ? $c : null; } }
        function g($x) { try { return $x instanceof Never; } catch (Never $e) { return 1; } }',
    );

    expect($result)->toContain('function f(\ArrayColl|\Persistent|null $c, \ArrayColl|\Persistent|int $d): \ArrayColl|\Persistent')
        ->toContain('catch (\DbError $e)')
        ->toContain('$c instanceof \ArrayColl || $c instanceof \Persistent')
        ->toContain('return false;')
        ->not->toContain('catch (Never');
});

it('keeps interfaces named by strings, static access or anonymous implementations', function (): void {
    $result = applyInterfaceTypeCheck(
        'interface Named {} interface Anon {} class A implements Named, Anon {}
        $x = new class implements Anon {}; $n = "Named";
        function f(Named $a, Anon $b) { return $a instanceof Named && $b instanceof Anon; }',
    );

    expect($result)->toContain('function f(Named $a, Anon $b)');
});

it('expands intersections and keeps ancestors of interfaces implemented outside the program', function (): void {
    $result = applyInterfaceTypeCheck(
        'interface ToMany {} abstract class Mapping {} class OneToMany extends Mapping implements ToMany {} class ManyToOne extends Mapping {}
        interface Proxy {} interface InternalProxy extends Proxy {} $template = "InternalProxy";
        function f(Mapping&ToMany $m, $e) { return $e instanceof Proxy; }',
    );

    expect($result)->toContain('function f(\OneToMany $m, $e)')
        ->toContain('return $e instanceof Proxy;');
});

it('treats interface names used as labels or in replaceable checks as removable', function (): void {
    $result = applyInterfaceTypeCheck(
        'interface Ext {} interface NumberExt extends Ext {} interface Attr {}
        class Number implements NumberExt {} class Entity implements Attr {}
        class Container { private $defs = []; public function add($id, $def) { $this->defs[$id] = $def; }
            public function get($id) { if (!isset($this->defs[$id])) { throw new \RuntimeException(sprintf("No %s", $id)); } return $this->defs[$id]; } }
        $c = new Container(); $c->add("NumberExt", "Number"); $n = $c->get("NumberExt");
        function check($name, $obj) { return [is_subclass_of($name, "Attr"), in_array("Attr", class_implements($obj))]; }
        function f(Ext $e) { return $e; }',
    );

    expect($result)->toContain('function f(\Number $e)')
        ->toContain('return [\is_a($name, \'Entity\', true), \is_a($obj, \'Entity\', true)];');
});
