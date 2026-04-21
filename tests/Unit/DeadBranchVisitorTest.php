<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;

beforeEach(function (): void {
    $this->parser  = new ParserFactory()->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->visitor = new DeadBranchVisitor();
});

function applyDeadBranch(string $code, DeadBranchVisitor $visitor): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);
    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('removes if(false) entirely', function (): void {
    $result = applyDeadBranch('<?php if (false) { echo "dead"; }', $this->visitor);
    expect($result)->toBe('');
});

it('extracts body of if(true)', function (): void {
    $result = applyDeadBranch('<?php if (true) { echo "live"; }', $this->visitor);
    expect($result)->toContain('live');
    expect($result)->not->toContain('if');
});

it('uses else branch of if(false)', function (): void {
    $result = applyDeadBranch('<?php if (false) { echo "dead"; } else { echo "live"; }', $this->visitor);
    expect($result)->toContain('live');
    expect($result)->not->toContain('dead');
    expect($result)->not->toContain('if');
});

it('discards else of if(true)', function (): void {
    $result = applyDeadBranch('<?php if (true) { echo "live"; } else { echo "dead"; }', $this->visitor);
    expect($result)->toContain('live');
    expect($result)->not->toContain('dead');
});

it('converts elseif to if when condition is false', function (): void {
    $code   = '<?php if (false) { echo "a"; } elseif ($x) { echo "b"; }';
    $result = applyDeadBranch($code, $this->visitor);
    expect($result)->toContain('if ($x)');
    expect($result)->toContain('"b"');
    expect($result)->not->toContain('"a"');
});

it('preserves normal if unchanged', function (): void {
    $result = applyDeadBranch('<?php if ($x > 0) { echo "pos"; }', $this->visitor);
    expect($result)->toContain('if ($x > 0)');
    expect($result)->toContain('pos');
});

it('removes empty if(true) body without error', function (): void {
    $result = applyDeadBranch('<?php if (true) {}', $this->visitor);
    expect($result)->toBe('');
});
