<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\AstProfile;
use Recombinator\Transformation\RuleScheduler;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
use Recombinator\Transformation\Visitor\RemoveInterfacesVisitor;

/** @return array<Node> */
function schedulerAst(string $code): array
{
    return new ParserFactory()->createForHostVersion()->parse($code) ?? [];
}

/**
 * Прогон правил до неподвижной точки, как в public/api.php.
 *
 * @param  list<class-string<PhpParser\NodeVisitorAbstract>> $ruleClasses
 * @return array{string, list<string>, RuleScheduler}
 */
function scheduleRun(string $code, array $ruleClasses, string $mode): array
{
    $printer   = new StandardPrinter();
    $ast       = schedulerAst($code);
    $scheduler = new RuleScheduler($mode, count($ruleClasses));
    $order     = [];
    while (($index = $scheduler->next($rules = array_map(static fn(string $c): object => new $c(), $ruleClasses), $ast)) !== null) {
        $before  = $printer->prettyPrint($ast);
        $ast     = new NodeTraverser($rules[$index])->traverse($ast);
        $order[] = $ruleClasses[$index];
        $scheduler->done($index, $printer->prettyPrint($ast) !== $before);
    }

    return [$printer->prettyPrint($ast), $order, $scheduler];
}

it('profiles node counts and subtree sizes, matching abstract types', function (): void {
    $profile = new AstProfile(schedulerAst('<?php $a = 1 + 2 * 3;'));

    // Expression, Assign, Variable, Plus, Int 1, Mul, Int 2, Int 3
    expect($profile->size)->toBe(8)
        ->and($profile->match([Node\Expr\BinaryOp::class]))->toBe([2, 8])
        ->and($profile->match([Node\Expr\BinaryOp\Mul::class]))->toBe([1, 3])
        ->and($profile->match([Node\Stmt\If_::class]))->toBe([0, 0]);
});

it('estimates zero candidates when the AST has no target nodes', function (): void {
    $profile = new AstProfile(schedulerAst('<?php echo $x;'));

    expect(new ConstFoldVisitor()->estimate($profile)?->candidates)->toBe(0)
        ->and(new DeadBranchVisitor()->estimate($profile)?->candidates)->toBe(0)
        ->and(new ConstFoldVisitor()->estimate(new AstProfile(schedulerAst('<?php echo 1 + 2;'))))->not->toBeNull()
        ->and(new ConstFoldVisitor()->estimate(new AstProfile(schedulerAst('<?php echo 1 + 2;'))))->candidates->toBe(1);
});

it('runs every rule once per pass and converges when no rule changes code', function (): void {
    $rules = [DeadBranchVisitor::class, ConstFoldVisitor::class, CoalesceNullRemoveVisitor::class];
    [$code, $order, $scheduler] = scheduleRun('<?php if (1 + 1 === 2) { echo "a"; } else { echo "b"; }', $rules, RuleScheduler::SEQUENTIAL);

    // Проход 1: DeadBranch (ничего), ConstFold (свернул условие), Coalesce;
    // проход 2: DeadBranch убрал ветку, затем остальные подтверждают неподвижную точку
    expect($code)->toBe('echo "a";')
        ->and($order)->toBe([...$rules, DeadBranchVisitor::class, ConstFoldVisitor::class, CoalesceNullRemoveVisitor::class, DeadBranchVisitor::class])
        ->and($scheduler->pass)->toBe(3);
});

it('skips rules without candidates in estimate mode and gets the same result', function (): void {
    $rules = [DeadBranchVisitor::class, ConstFoldVisitor::class, CoalesceNullRemoveVisitor::class, RemoveInterfacesVisitor::class];
    $code  = '<?php if (1 + 1 === 2) { echo "a"; } else { echo "b"; }';

    [$sequential, $sequentialOrder] = scheduleRun($code, $rules, RuleScheduler::SEQUENTIAL);
    [$estimated, $estimatedOrder, $scheduler] = scheduleRun($code, $rules, RuleScheduler::ESTIMATE);

    expect($estimated)->toBe($sequential)
        ->and($estimatedOrder)->not->toContain(CoalesceNullRemoveVisitor::class)
        ->and($estimatedOrder)->not->toContain(RemoveInterfacesVisitor::class)
        ->and(count($estimatedOrder))->toBeLessThan(count($sequentialOrder))
        ->and($scheduler->skipped)->toBeGreaterThan(0);
});

it('survives serialization without the AST profile', function (): void {
    $scheduler = new RuleScheduler(RuleScheduler::ESTIMATE, 2);
    $scheduler->done(0, true);

    $restored = unserialize(serialize($scheduler));

    expect($restored)->toBeInstanceOf(RuleScheduler::class)
        ->and($restored->mode)->toBe(RuleScheduler::ESTIMATE)
        ->and($restored->runs())->toBe(1);
});

it('rejects an unknown mode', function (): void {
    new RuleScheduler('random', 1);
})->throws(InvalidArgumentException::class);
