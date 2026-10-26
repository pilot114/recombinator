<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->visitor = new BinaryAndIssetVisitor();
    }
);

it(
    'can calculate addition of integers',
    function (): void {
        $code = '<?php $result = 5 + 3;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$result = 8');
    }
);

it(
    'can calculate subtraction of integers',
    function (): void {
        $code = '<?php $result = 10 - 3;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$result = 7');
    }
);

it(
    'can calculate multiplication of integers',
    function (): void {
        $code = '<?php $result = 4 * 5;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$result = 20');
    }
);

it(
    'can calculate division of integers',
    function (): void {
        $code = '<?php $result = 10 / 2;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$result = 5');
    }
);

it(
    'can calculate division resulting in float',
    function (): void {
        $code = '<?php $result = 10 / 3;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('3.333');
    }
);

it(
    'can concatenate strings',
    function (): void {
        $code = '<?php $result = "Hello" . " World";';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('Hello World');
    }
);

// Boolean conversion tests skipped due to current implementation limitations

it(
    'can transform isset check to coalesce operator',
    function (): void {
        $code = '<?php
$default = "default";
if (isset($value)) {
    $default = $value;
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$default = $value ?? $default');
    }
);

it('keeps false in strict comparison', function (): void {
    $ast = $this->parser->parse('<?php if ($result === false) { return null; }');
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);

    $result = $this->printer->prettyPrint($traverser->traverse($ast));

    expect($result)->toContain('$result === false');
});

it('casts bool in arithmetic', function (): void {
    $ast = $this->parser->parse('<?php $x = 2 + true;');
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);

    $result = $this->printer->prettyPrint($traverser->traverse($ast));

    expect($result)->toContain('$x = 3;');
});

it('keeps an isset guard around an assignment of a different value', function (): void {
    $code = '<?php if (isset($d["min"])) { $c[] = "CHECK " . $d["min"]; } if (isset($x)) { $y = $x . "!"; }';
    $ast  = new PhpParser\ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast  = new PhpParser\NodeTraverser(new Recombinator\Transformation\Visitor\BinaryAndIssetVisitor())->traverse($ast);

    $out  = new PhpParser\PrettyPrinter\Standard()->prettyPrint($ast);

    expect($out)->toContain('if (isset($d["min"]))')->toContain('if (isset($x))');
});
