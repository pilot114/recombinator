<?php

declare(strict_types=1);

use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use Recombinator\Fluent;

it('can create fluent instance with ast', function () {
    $ast = [new Echo_([new String_('test')])];
    $fluent = new Fluent($ast);

    expect($fluent)->toBeInstanceOf(Fluent::class);
});

it('can add visitors', function () {
    $ast = [new Echo_([new String_('test')])];
    $fluent = new Fluent($ast);

    $visitor = new class extends \PhpParser\NodeVisitorAbstract {};
    $result = $fluent->withVisitors([$visitor]);

    expect($result)->toBeInstanceOf(Fluent::class);
});

it('can modify ast with visitors', function () {
    $ast = [new Echo_([new String_('test')])];
    $fluent = new Fluent($ast);

    $modified = $fluent->modify();

    expect($modified)->toBeArray();
});

it('returns fluent instance from withVisitors for chaining', function () {
    $ast = [new Echo_([new String_('test')])];
    $visitor1 = new class extends \PhpParser\NodeVisitorAbstract {};
    $visitor2 = new class extends \PhpParser\NodeVisitorAbstract {};

    $fluent = (new Fluent($ast))
        ->withVisitors([$visitor1])
        ->withVisitors([$visitor2]);

    expect($fluent)->toBeInstanceOf(Fluent::class);
});
