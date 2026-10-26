<?php

declare(strict_types=1);

namespace Recombinator\Transformation\State;

use PhpParser\Node;

/** @internal */
final readonly class RunResult
{
    /** @param list<Node\Stmt> $emitted */
    public function __construct(
        public ObjectState $state,
        public array $emitted,
        public Node\Expr|bool $value,
        public bool $wrote,
    ) {
    }
}
