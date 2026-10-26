<?php

declare(strict_types=1);

namespace Recombinator\Transformation\State;

use PhpParser\Node;

/** @internal */
final class RunContext
{
    /** @var array<string, Node\Expr|null> */
    public array $env = [];

    /** @var list<Node\Stmt> */
    public array $emitted = [];

    public bool $wrote = false;

    /** @param 'simulate'|'specialize' $mode */
    public function __construct(
        public readonly string $var,
        public ObjectState $state,
        public readonly string $mode,
        public readonly string $prefix,
        public readonly int $depth,
    ) {
    }
}
