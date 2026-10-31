<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\Transformation\BodyInliner;

/**
 * Инлайнинг одноразовых переменных-посредников.
 *
 * Если переменная присваивается ровно один раз и читается ровно один раз,
 * выражение из правой части подставляется прямо в место чтения,
 * а присваивание удаляется.
 *
 * Пример:
 *   $result = $a . '_' . $b === 'x' ? 'ok' : 'fail';
 *   echo $result . "\n";
 * →
 *   echo ($a . '_' . $b === 'x' ? 'ok' : 'fail') . "\n";
 *
 * Анализ (beforeTraverse) выполняется по уже расставленным ссылкам
 * на родителей от NodeConnectingVisitor, поэтому работает корректно
 * в рамках стандартного конвейера applyVisitor().
 */
#[VisitorMeta('Инлайнинг одноразовых переменных (writeCount=1, readCount=1)', targets: [Node\Expr\Assign::class])]
class SingleUseInlinerVisitor extends BaseVisitor
{
    /** @var array<string, Node\Expr> scopeId:varName → expression to inline */
    private array $toInline = [];

    /** @var \SplObjectStorage<Node\Expr\Variable, string> чтение → ключ подстановки */
    private \SplObjectStorage $targets;

    /** @var array<string, array<int|string, true>>|null параметры по ссылке (BodyInliner::byRefParams) */
    private ?array $byRef = null;

    public function __construct()
    {
        $this->targets = new \SplObjectStorage();
    }

    // ── Analysis phase ───────────────────────────────────────────────────────

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->byRef = null;
        $this->toInline = [];
        $this->targets  = new \SplObjectStorage();

        /** @var array<string, int> */
        $writeCounts = [];
        /** @var array<string, int> */
        $readCounts = [];
        /** @var array<string, Node\Expr> */
        $assignExprs = [];
        /** @var array<string, Node\Expr\Assign> */
        $assignNodes = [];
        /** @var array<string, list<Node\Expr\Variable>> */
        $readVarNodes = [];

        // NodeConnectingVisitor has already run — parent refs are valid.
        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (!is_string($var->name)) {
                continue;
            }

            // Переменные разных функций — разные переменные
            $name   = $this->scopeId($var) . ':' . $var->name;
            $parent = $var->getAttribute('parent');

            // Объявление параметра — тоже запись (значение приходит из вызова)
            if ($parent instanceof Node\Param) {
                $writeCounts[$name] = ($writeCounts[$name] ?? 0) + 2;
                continue;
            }

            if ($parent instanceof Node\Expr\Assign && $parent->var === $var) {
                // Write: $name = expr
                $writeCounts[$name]  = ($writeCounts[$name] ?? 0) + 1;
                $assignExprs[$name]  = $parent->expr;
                $assignNodes[$name]  = $parent;
            } else {
                // Read
                $readCounts[$name]    = ($readCounts[$name] ?? 0) + 1;
                $readVarNodes[$name][] = $var;
            }
        }

        foreach ($writeCounts as $name => $writeCount) {
            $readCount = $readCounts[$name] ?? 0;
            if ($writeCount !== 1) {
                continue;
            }

            if ($readCount !== 1) {
                continue;
            }

            // The single read must not be inside the assignment's own RHS
            // (e.g. $x = $x + 1 has read inside the assign — skip it)
            $readNode   = $readVarNodes[$name][0];
            $assignNode = $assignNodes[$name];
            if ($this->isInsideNode($readNode, $assignNode)) {
                continue;
            }

            // Чтение в позиции записи ($x++, $x .= …, $x[] = …, по ссылке) — это не чтение
            if ($this->isWriteContext($readNode)) {
                continue;
            }

            // Чтение в цикле, который не содержит присваивания, выполняется многократно
            if ($this->loopBetween($readNode, $assignNode)) {
                continue;
            }

            // В интерполяции строки допустимы только выражения, начинающиеся с переменной:
            // "{implode(...)}" — уже не подстановка, а текст
            if (!$assignNode->expr instanceof Node\Expr\Variable && $this->insideInterpolation($readNode)) {
                continue;
            }

            // Mark the wrapping Stmt\Expression for removal
            $stmtParent = $assignNode->getAttribute('parent');
            if (!($stmtParent instanceof Node\Stmt\Expression)) {
                continue; // assignment not in a simple expression statement — skip
            }

            // Вычисление переносится к месту чтения: между ними не должно быть других
            // эффектов (`try { $r = f(); } finally { g(); } return $r;` — f() после g())
            if (!$this->readFollowsDirectly($readNode, $stmtParent)) {
                continue;
            }

            $stmtParent->setAttribute('remove', true);
            $this->toInline[$name] = $assignNode->expr;
            $this->targets[$readNode] = $name;
        }

        return null;
    }

    // ── Transform phase ──────────────────────────────────────────────────────

    #[\Override]
    public function enterNode(Node $node): ?Node
    {
        if (!($node instanceof Node\Expr\Variable) || !isset($this->targets[$node])) {
            return null;
        }

        $name = $this->targets[$node];
        if (!isset($this->toInline[$name])) {
            return null;
        }

        // Inline: replace the read with a deep clone of the stored expression
        $expr = $this->deepClone($this->toInline[$name]);
        unset($this->toInline[$name]); // consume — only substitute once

        return $expr;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Идентификатор области видимости переменной: ближайшая функция/метод или файл.
     * Переменная из use замыкания принадлежит внешней области (читается при создании);
     * стрелочная функция своей области не создаёт (захватывает внешние переменные).
     */
    private function scopeId(Node $node): string
    {
        $p = $node->getAttribute('parent');
        if ($p instanceof Node\ClosureUse) {
            $p = $p->getAttribute('parent')?->getAttribute('parent');
        }

        for (; $p instanceof Node; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\FunctionLike && !$p instanceof Node\Expr\ArrowFunction) {
                return (string) spl_object_id($p);
            }
        }

        return 'top';
    }

    private function isWriteContext(Node\Expr\Variable $var): bool
    {
        $child  = $var;
        $parent = $var->getAttribute('parent');
        // $x[…] = / $x->p = — запись в саму переменную
        while (($parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\Expr\PropertyFetch) && $parent->var === $child) {
            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return match (true) {
            $parent instanceof Node\Expr\Assign, $parent instanceof Node\Expr\AssignOp => $parent->var === $child,
            $parent instanceof Node\Expr\AssignRef => true,
            $parent instanceof Node\Expr\PreInc, $parent instanceof Node\Expr\PreDec,
            $parent instanceof Node\Expr\PostInc, $parent instanceof Node\Expr\PostDec,
            $parent instanceof Node\Stmt\Unset_, $parent instanceof Node\Expr\List_,
            $parent instanceof Node\ArrayItem && $parent->byRef,
            $parent instanceof Node\ClosureUse, $parent instanceof Node\StaticVar, $parent instanceof Node\Stmt\Global_ => true,
            $parent instanceof Node\Stmt\Foreach_ => $parent->keyVar === $child || $parent->valueVar === $child,
            $parent instanceof Node\Arg => $parent->byRef || $this->argMayBeByRef($parent),
            default => false,
        };
    }

    private function argMayBeByRef(Node\Arg $arg): bool
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
            return BodyInliner::mayPassByRef($call, $index);
        }

        // Метод или функция программы: `$this->doPersist($entity, $visited)` с `array &$visited`
        $name = match (true) {
            $call instanceof Node\Expr\New_ => '__construct',
            $call instanceof Node\Expr\FuncCall => $call->name instanceof Node\Name ? $call->name->getLast() : null,
            default => $call->name instanceof Node\Identifier ? $call->name->toString() : null,
        };
        if ($name === null) {
            return true;
        }

        $this->byRef ??= BodyInliner::byRefParams($this->ast);
        $refs = $this->byRef[strtolower($name)] ?? [];

        return isset($refs[$index]) || isset($refs['*']) || $refs !== [] && $arg->name instanceof \PhpParser\Node\Identifier;
    }

    private function loopBetween(Node $read, Node $assign): bool
    {
        for ($p = $read->getAttribute('parent'); $p instanceof Node && !$p instanceof Node\FunctionLike; $p = $p->getAttribute('parent')) {
            if (
                ($p instanceof Node\Stmt\For_ || $p instanceof Node\Stmt\Foreach_ || $p instanceof Node\Stmt\While_ || $p instanceof Node\Stmt\Do_)
                && !$this->isInsideNode($assign, $p)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if $child is a descendant of $container in the AST
     * (using the parent refs set by NodeConnectingVisitor).
     */
    /**
     * Чтение — в следующем за присваиванием операторе того же блока, и в этом
     * операторе до чтения не вычисляется ничего с побочными эффектами.
     */
    private function readFollowsDirectly(Node $read, Node\Stmt\Expression $assignStmt): bool
    {
        // Верхний уровень: у операторов нет parent, соседи — корень AST
        $owner  = $assignStmt->getAttribute('parent');
        $holder = $read;
        while ($holder instanceof Node && $holder->getAttribute('parent') !== $owner) {
            $holder = $holder->getAttribute('parent');
        }

        if (!$holder instanceof Node\Stmt) {
            return false;
        }

        $siblings = $owner instanceof Node ? null : array_values($this->ast);
        if ($owner instanceof Node) {
            foreach ($owner->getSubNodeNames() as $name) {
                if (is_array($owner->$name) && in_array($assignStmt, $owner->$name, true)) {
                    $siblings = array_values($owner->$name);
                }
            }
        }

        $at = $siblings === null ? false : array_search($assignStmt, $siblings, true);
        if (!is_int($at) || ($siblings[$at + 1] ?? null) !== $holder) {
            return false;
        }

        $readStart = $read->getStartFilePos();
        $ancestors = new \SplObjectStorage();
        for ($node = $read; $node instanceof Node && $node !== $holder; $node = $node->getAttribute('parent')) {
            $ancestors[$node] = true;
        }

        foreach ($this->findNode(Node::class, $holder) as $node) {
            if (isset($ancestors[$node]) || !$this->hasEffect($node)) {
                continue;
            }

            // Эффект вычисляется раньше чтения (или позиция неизвестна) — переносить нельзя
            if ($readStart < 0 || $node->getStartFilePos() < 0 || $node->getStartFilePos() < $readStart) {
                return false;
            }
        }

        return true;
    }

    private function hasEffect(Node $node): bool
    {
        return $node instanceof Node\Expr\CallLike
            || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
            || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
            || $node instanceof Node\Expr\Print_ || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\Throw_
            || $node instanceof Node\Stmt\Echo_ && count($node->exprs) > 1
            || $node instanceof Node\Expr\Eval_;
    }

    private function insideInterpolation(Node $node): bool
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\Scalar\InterpolatedString) {
                return true;
            }

            if ($parent instanceof Node\Stmt) {
                return false;
            }
        }

        return false;
    }

    private function isInsideNode(Node $child, Node $container): bool
    {
        $current = $child->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current === $container) {
                return true;
            }

            $current = $current->getAttribute('parent');
        }

        return false;
    }
}
