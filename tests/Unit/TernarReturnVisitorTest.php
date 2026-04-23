<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->visitor = new TernarReturnVisitor();
    }
);

it(
    'can convert if-return pattern to ternary operator',
    function (): void {
        $code = '<?php
class Test {
    public function check($x) {
        if ($x > 0) {
            return "positive";
        }
        return "negative";
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // Accept both single and double quotes (php-parser may use either)
        expect($result)->toMatch('/return \$x > 0 \? ["\']positive["\'] : ["\']negative["\']/');
        expect($result)->not->toContain('if ($x > 0)');
    }
);

it(
    'can convert if-return with boolean condition',
    function (): void {
        $code = '<?php
class Test {
    public function isValid($flag) {
        if ($flag) {
            return true;
        }
        return false;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $flag ? true : false');
    }
);

it(
    'can convert if-return with numeric values',
    function (): void {
        $code = '<?php
class Math {
    public function sign($x) {
        if ($x > 0) {
            return 1;
        }
        return -1;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $x > 0 ? 1 : -1');
    }
);

it(
    'can convert if-return with expressions',
    function (): void {
        $code = '<?php
class Calculator {
    public function calculate($x, $y) {
        if ($x > $y) {
            return $x + $y;
        }
        return $x - $y;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $x > $y ? $x + $y : $x - $y');
    }
);

it(
    'should not convert method without if-return pattern',
    function (): void {
        $code = '<?php
class Test {
    public function normal($x) {
        return $x * 2;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $x * 2');
        expect($result)->not->toContain('?');
    }
);

it(
    'should not convert if without return in body',
    function (): void {
        $code = '<?php
class Test {
    public function test($x) {
        if ($x > 0) {
            $y = $x * 2;
        }
        return $y;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('if ($x > 0)');
        expect($result)->not->toContain('? $x * 2 :');
    }
);

it(
    'can handle complex conditions',
    function (): void {
        $code = '<?php
class Test {
    public function check($x, $y) {
        if ($x > 0 && $y < 10) {
            return "valid";
        }
        return "invalid";
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // Accept both single and double quotes (php-parser may use either)
        expect($result)->toMatch('/return \$x > 0 && \$y < 10 \? ["\']valid["\'] : ["\']invalid["\']/');
    }
);

it(
    'can convert if-return with variable returns',
    function (): void {
        $code = '<?php
class Test {
    public function pick($choice, $a, $b) {
        if ($choice) {
            return $a;
        }
        return $b;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $choice ? $a : $b');
    }
);

it(
    'can convert if-else pattern in standalone function',
    function (): void {
        $code = '<?php
function hsum_rec($a, $b, $p) {
    $r = hsum($a, $b, $p);
    if ($r == $p) {
        return [$r, $p];
    } else {
        return hsum_rec($a, $b, $r);
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('return $r == $p ? [$r, $p] : hsum_rec($a, $b, $r)');
        expect($result)->not->toContain('if (');
        // prefix statement preserved
        expect($result)->toContain('$r = hsum(');
    }
);

it(
    'can convert if-else with both branches returning in method',
    function (): void {
        $code = '<?php
class Foo {
    public function bar($x) {
        if ($x > 0) {
            return "pos";
        } else {
            return "neg";
        }
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toMatch('/return \$x > 0 \? ["\']pos["\'] : ["\']neg["\']/');
        expect($result)->not->toContain('if (');
    }
);

it(
    'preserves prefix statements when converting last if-return pair',
    function (): void {
        $code = '<?php
function check($x) {
    $y = $x * 2;
    if ($y > 0) {
        return 1;
    }
    return 0;
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$y = $x * 2');
        expect($result)->toContain('return $y > 0 ? 1 : 0');
        expect($result)->not->toContain('if (');
    }
);
