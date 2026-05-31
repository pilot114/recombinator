<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Подмена переменных их значениями (скаляры)
 */
#[VisitorMeta('Подстановка скалярных переменных вместо промежуточных присваиваний')]
class VarToScalarVisitor extends BaseVisitor
{
    public ScopeStore $scopeStore;

    /**
     * TODO не всегда возможно удалить
     * $test = 'abc';
     * if ($b) {
     *    $test = $b;
     * }
     * тут удалить скаляр нельзя
     */
    /**
     * @var mixed
     */
    public $maybeRemove;

    /**
     * Переменные, которые переопределяются внутри условно-исполняемой конструкции
     * (циклы, if/elseif/else, switch/match, try/catch). Их значения зависят от
     * потока управления и не могут кешироваться как скалярные.
     *
     * @var array<string, true>
     */
    private array $conditionalAssignedVars = [];

    /** Depth of ClassMethod/Function nesting — substitution is disabled when > 0. */
    private int $methodDepth = 0;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->conditionalAssignedVars = [];
        $this->methodDepth = 0;

        foreach (
            [
            Node\Stmt\Foreach_::class,
            Node\Stmt\For_::class,
            Node\Stmt\While_::class,
            Node\Stmt\Do_::class,
            Node\Stmt\If_::class,
            Node\Stmt\Switch_::class,
            Node\Expr\Match_::class,
            Node\Stmt\TryCatch::class,
            ] as $scopedClass
        ) {
            foreach ($this->findNode($scopedClass) as $scoped) {
                foreach ($this->findNode(Node\Expr\Assign::class, $scoped) as $assign) {
                    if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                        $this->conditionalAssignedVars[$assign->var->name] = true;
                    }
                }

                // Increment/decrement operators and compound-assign operators also mutate variables
                foreach (
                    [
                    Node\Expr\PreInc::class, Node\Expr\PreDec::class,
                    Node\Expr\PostInc::class, Node\Expr\PostDec::class,
                    ] as $mutClass
                ) {
                    foreach ($this->findNode($mutClass, $scoped) as $mut) {
                        if (property_exists($mut, 'var') && $mut->var instanceof Node\Expr\Variable && is_string($mut->var->name)) {
                            $this->conditionalAssignedVars[$mut->var->name] = true;
                        }
                    }
                }

                foreach ($this->findNode(Node\Expr\AssignOp::class, $scoped) as $assignOp) {
                    if ($assignOp->var instanceof Node\Expr\Variable && is_string($assignOp->var->name)) {
                        $this->conditionalAssignedVars[$assignOp->var->name] = true;
                    }
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        // Class methods have their own variable scope; outer-scope scalar values
        // must not bleed into them. Closures and arrow functions are excluded
        // because they legitimately capture outer-scope variables.
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodDepth++;
            return null;
        }

        if ($this->methodDepth > 0) {
            return null;
        }

        /**
         * Замена переменных 1/3 - Запись скаляра
         * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
         * подменяем только переменные со скалярным значением.
         * Переменные, переопределяемые внутри циклов или ветвей (if/switch/match/try),
         * не кешируем — их значение зависит от потока управления, а не от первой записи.
         */
        if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $varName = $node->var->name;
            // Support both Scalar values and ConstFetch (true, false, null)
            $isScalar = $node->expr instanceof Node\Scalar;
            $isConstFetch = $node->expr instanceof Node\Expr\ConstFetch &&
                            in_array(strtolower($node->expr->name->toString()), ['true', 'false', 'null'], true);

            if (($isScalar || $isConstFetch) && !isset($this->conditionalAssignedVars[$varName])) {
                // заносим в кеш
                $varExprScalar = clone $node->expr;
                $varExprScalar->setAttributes([]);
                $this->scopeStore->setVarToScope($varName, $varExprScalar);

                $parent = $node->getAttribute('parent');
                if ($parent instanceof Node) {
                    $parent->setAttribute('remove', true);


                    return null;
                }
            }
        }

        /**
         * Scalar mutation tracking: $x++ / ++$x / $x-- / --$x / $x += N
         * If $x is in scalar cache, update the cache value and remove the statement.
         */
        if (
            ($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PostDec || $node instanceof Node\Expr\PreDec)
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && !isset($this->conditionalAssignedVars[$node->var->name])
        ) {
            $varName = $node->var->name;
            $cached  = $this->scopeStore->getVarFromScope($varName);
            if ($cached instanceof Node\Scalar\LNumber) {
                $delta = ($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreInc) ? 1 : -1;
                $this->scopeStore->setVarToScope($varName, new Node\Scalar\LNumber($cached->value + $delta));
                $parent = $node->getAttribute('parent');
                if ($parent instanceof Node\Stmt\Expression) {
                    $parent->setAttribute('remove', true);
                }
            }
        }

        if (
            $node instanceof Node\Expr\AssignOp\Plus
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && !isset($this->conditionalAssignedVars[$node->var->name])
            && $node->expr instanceof Node\Scalar\LNumber
        ) {
            $varName = $node->var->name;
            $cached  = $this->scopeStore->getVarFromScope($varName);
            if ($cached instanceof Node\Scalar\LNumber) {
                $this->scopeStore->setVarToScope($varName, new Node\Scalar\LNumber($cached->value + $node->expr->value));
                $parent = $node->getAttribute('parent');
                if ($parent instanceof Node\Stmt\Expression) {
                    $parent->setAttribute('remove', true);
                }
            }
        }

        /**
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - обновляем
         */
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $varName = $node->name;

            $isRead = $this->isVarRead($node, $varName);
            $cachedValue = $this->scopeStore->getVarFromScope($varName);
            if ($isRead && $cachedValue !== null) {
                // Внутри интерполированной строки замену нужно делать через
                // InterpolatedStringPart, иначе принтер обернёт скаляр в {expr}
                $parent = $node->getAttribute('parent');
                if ($parent instanceof Node\Scalar\Encapsed) {
                    $strVal = $this->scalarToInterpolatedValue($cachedValue);
                    if ($strVal !== null) {
                        $node->setAttribute('replace', new Node\InterpolatedStringPart($strVal));
                        return null;
                    }
                }

                $node->setAttribute('replace', $cachedValue);
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodDepth--;
        }

        /**
         * Замена переменных 3/3 - Запись нескаляра
         * Очищаем кеш ПОСЛЕ обхода дочерних узлов, чтобы правая часть
         * присваивания успела получить подстановку.
         * Например: $x = $_GET['x'] ?? $x ?? null
         *   → сначала $x справа заменяется на скаляр из кеша,
         *   → потом кеш очищается (переменная переопределена не-скаляром).
         */
        if ($this->methodDepth > 0) {
            return parent::leaveNode($node);
        }

        if (property_exists($node, 'expr') && $node->expr !== null && $node->expr instanceof Node\Expr\Assign) {
            $assign = $node->expr;
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                $varName = $assign->var->name;
                $isScalar = $assign->expr instanceof Node\Scalar;
                $isConstFetch = $assign->expr instanceof Node\Expr\ConstFetch &&
                                in_array(strtolower($assign->expr->name->toString()), ['true', 'false', 'null'], true);

                if (!$isScalar && !$isConstFetch && $this->scopeStore->getVarFromScope($varName) !== null) {
                    $this->scopeStore->removeVarFromScope($varName);
                }
            }
        }

        return parent::leaveNode($node);
    }

    /**
     * Проверяем, что это чтение переменной
     */
    protected function isVarRead(Node $node, string $varName): bool
    {
        $parent = $node->getAttribute('parent');

        // LHS of plain assignment
        if ($parent instanceof Node\Expr\Assign && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName) {
            return false;
        }

        // LHS of compound-assign ($x += ..., $x .= ..., etc.)
        if ($parent instanceof Node\Expr\AssignOp && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName) {
            return false;
        }

        // Target of ++/-- operators
        return !(($parent instanceof Node\Expr\PostInc || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostDec || $parent instanceof Node\Expr\PreDec) && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName);
    }

    /**
     * Возвращает строковое значение скалярного узла для подстановки в Encapsed,
     * или null если узел не может быть представлен строкой в интерполяции.
     */
    private function scalarToInterpolatedValue(Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\LNumber) {
            return (string) $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return (string) $node->value;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true'  => '1',
                'false' => '',
                'null'  => '',
                default => null,
            };
        }

        return null;
    }
}
