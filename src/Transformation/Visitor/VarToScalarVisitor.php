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

    /**
     * Случаи enum (`lowercase enum::case`): объект-одиночка, неизменяемый, как константа.
     *
     * @var array<string, true>
     */
    private array $enumCases = [];

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
        $this->enumCases = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\Enum_ && $stmt->name instanceof Node\Identifier) {
                    foreach ($stmt->stmts as $case) {
                        if ($case instanceof Node\Stmt\EnumCase) {
                            $this->enumCases[strtolower($stmt->name->toString() . '::' . $case->name->toString())] = true;
                        }
                    }
                }
            }
        }

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

        $this->markIndirectWrites();

        return null;
    }

    /**
     * Переменные, которые меняются не простым присваиванием: по ссылке в вызов
     * (`inc($n)` с `&$n`), `unset`, `list()`, `foreach`, `global`, `static`,
     * `use (&$x)`, `$x = &$y`. Их значение после такой записи неизвестно.
     */
    private function markIndirectWrites(): void
    {
        $byRef = null;
        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (!is_string($var->name)) {
                continue;
            }

            $parent  = $var->getAttribute('parent');
            $written = match (true) {
                $parent instanceof Node\Expr\AssignRef, $parent instanceof Node\Stmt\Unset_, $parent instanceof Node\Expr\List_,
                $parent instanceof Node\Stmt\Global_, $parent instanceof Node\StaticVar => true,
                $parent instanceof Node\ClosureUse => $parent->byRef,
                $parent instanceof Node\ArrayItem => $parent->byRef || $parent->getAttribute('parent') instanceof Node\Expr\List_,
                $parent instanceof Node\Stmt\Foreach_ => $parent->keyVar === $var || $parent->valueVar === $var,
                $parent instanceof Node\Arg => $this->argMayBeByRef($parent, $byRef),
                default => false,
            };
            if ($written) {
                $this->conditionalAssignedVars[$var->name] = true;
            }
        }
    }

    /** @param array<string, array<int|string, true>>|null $byRef */
    private function argMayBeByRef(Node\Arg $arg, ?array &$byRef): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$call instanceof Node\Expr\CallLike || $call->isFirstClassCallable()) {
            return false;
        }

        $index = array_search($arg, $call->getRawArgs(), true);
        if (!is_int($index)) {
            return true;
        }

        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && function_exists($call->name->getLast())) {
            return \Recombinator\Transformation\BodyInliner::mayPassByRef($call, $index);
        }

        $name = match (true) {
            $call instanceof Node\Expr\New_ => '__construct',
            $call instanceof Node\Expr\FuncCall => $call->name instanceof Node\Name ? $call->name->getLast() : null,
            default => $call->name instanceof Node\Identifier ? $call->name->toString() : null,
        };
        if ($name === null) {
            return true;
        }

        $byRef ??= \Recombinator\Transformation\BodyInliner::byRefParams($this->ast);
        $refs    = $byRef[strtolower($name)] ?? [];

        return isset($refs[$index]) || isset($refs['*']) || $refs !== [] && $arg->name instanceof \PhpParser\Node\Identifier;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        // Class methods have their own variable scope; outer-scope scalar values
        // must not bleed into them. Closures and arrow functions are excluded
        // because they legitimately capture outer-scope variables.
        // Функции — тоже своя область видимости (значение из одной утекало бы в другую)
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
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
            if ($this->isConstantValue($node->expr) && !isset($this->conditionalAssignedVars[$varName])) {
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

                    if ($cachedValue instanceof Node\Expr\ClassConstFetch) {
                        return null; // "{E::Case}" в строке — не выражение
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
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
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
                if (!$this->isConstantValue($assign->expr) && $this->scopeStore->getVarFromScope($varName) !== null) {
                    $this->scopeStore->removeVarFromScope($varName);
                }
            }
        }

        return parent::leaveNode($node);
    }

    /** Скаляр, true/false/null или case enum. */
    private function isConstantValue(Node\Expr $expr): bool
    {
        // "{$x}…" — тоже Scalar, но значение зависит от переменных; __LINE__ и др. — от места
        if ($expr instanceof Node\Scalar\InterpolatedString || $expr instanceof Node\Scalar\MagicConst) {
            return false;
        }

        if ($expr instanceof Node\Scalar) {
            return true;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($expr->name->toString()), ['true', 'false', 'null'], true);
        }

        return $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && isset($this->enumCases[strtolower($expr->class->toString() . '::' . $expr->name->toString())]);
    }

    /**
     * Проверяем, что это чтение переменной
     */
    protected function isVarRead(Node $node, string $varName): bool
    {
        $parent = $node->getAttribute('parent');

        // Позиции, где нужна сама переменная, а не значение: захват замыкания, static/global,
        // unset/isset, catch, цели foreach и list(), ссылки, аргумент по ссылке
        $binding = $parent instanceof Node\Param || $parent instanceof Node\ClosureUse || $parent instanceof Node\StaticVar || $parent instanceof Node\Stmt\Global_
            || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Stmt\Catch_
            || $parent instanceof Node\Expr\AssignRef
            || $parent instanceof Node\Stmt\Foreach_ && ($parent->keyVar === $node || $parent->valueVar === $node)
            || $parent instanceof Node\ArrayItem && ($parent->byRef || $parent->getAttribute('parent') instanceof Node\Expr\List_)
            || $parent instanceof Node\Arg && $parent->byRef;
        if ($binding) {
            return false;
        }

        // LHS of plain assignment
        if ($parent instanceof Node\Expr\Assign && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName) {
            return false;
        }

        // LHS of compound-assign ($x += ..., $x .= ..., etc.)
        if ($parent instanceof Node\Expr\AssignOp && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName) {
            return false;
        }

        // Target of ++/-- operators
        $isIncDec = $parent instanceof Node\Expr\PostInc
            || $parent instanceof Node\Expr\PreInc
            || $parent instanceof Node\Expr\PostDec
            || $parent instanceof Node\Expr\PreDec;

        return !($isIncDec && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName);
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
