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

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->conditionalAssignedVars = [];

        foreach ([
            Node\Stmt\Foreach_::class,
            Node\Stmt\For_::class,
            Node\Stmt\While_::class,
            Node\Stmt\Do_::class,
            Node\Stmt\If_::class,
            Node\Stmt\Switch_::class,
            Node\Expr\Match_::class,
            Node\Stmt\TryCatch::class,
        ] as $scopedClass) {
            foreach ($this->findNode($scopedClass) as $scoped) {
                foreach ($this->findNode(Node\Expr\Assign::class, $scoped) as $assign) {
                    if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                        $this->conditionalAssignedVars[$assign->var->name] = true;
                    }
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
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
                            in_array(strtolower($node->expr->name->toString()), ['true', 'false', 'null']);

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
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - обновляем
         */
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $varName = $node->name;

            $isRead = $this->isVarRead($node, $varName);
            $cachedValue = $this->scopeStore->getVarFromScope($varName);
            if ($isRead && $cachedValue !== null) {
                $node->setAttribute('replace', $cachedValue);
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        /**
         * Замена переменных 3/3 - Запись нескаляра
         * Очищаем кеш ПОСЛЕ обхода дочерних узлов, чтобы правая часть
         * присваивания успела получить подстановку.
         * Например: $x = $_GET['x'] ?? $x ?? null
         *   → сначала $x справа заменяется на скаляр из кеша,
         *   → потом кеш очищается (переменная переопределена не-скаляром).
         */
        if (property_exists($node, 'expr') && $node->expr !== null && $node->expr instanceof Node\Expr\Assign) {
            $assign = $node->expr;
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                $varName = $assign->var->name;
                $isScalar = $assign->expr instanceof Node\Scalar;
                $isConstFetch = $assign->expr instanceof Node\Expr\ConstFetch &&
                                in_array(strtolower($assign->expr->name->toString()), ['true', 'false', 'null']);

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
        return !($parent instanceof Node\Expr\Assign && $parent->var instanceof Node\Expr\Variable && $parent->var->name === $varName);
    }
}
