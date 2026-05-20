<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\Core\Config;

/**
 * Удаляет определения функций, которые нигде не вызываются.
 *
 * Безопасно: рекурсивные функции остаются (вызов внутри тела считается использованием).
 * Динамические вызовы ($fn()) и строковые callable не отслеживаются — такие функции
 * не будут удалены только если есть явный статический вызов.
 */
#[VisitorMeta('Удаление неиспользуемых функций')]
class RemoveUnusedFunctionVisitor extends BaseVisitor
{
    /** @var array<string, true> */
    private array $calledFunctions = [];

    private bool $hasUnresolvedIncludes = false;

    private readonly Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->hasUnresolvedIncludes = $this->findNode(Node\Expr\Include_::class) !== [];
        if ($this->hasUnresolvedIncludes) {
            return null;
        }

        $this->calledFunctions = [];
        foreach ($this->findNode(Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name) {
                $this->calledFunctions[strtolower($call->name->toString())] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->hasUnresolvedIncludes || !$node instanceof Node\Stmt\Function_) {
            return null;
        }

        $name = strtolower((string) $node->name);
        if (!isset($this->calledFunctions[$name]) && !$this->config->isProtected($name)) {
            $node->setAttribute('remove', true);
        }

        return null;
    }
}
