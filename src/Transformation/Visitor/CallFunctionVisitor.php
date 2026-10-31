<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\Domain\ScopeStore;

/**
 * Замена вызова функции телом функции
 *
 * Аргументы при вызове вычисляются один раз и по порядку, поэтому сложный
 * аргумент (с вызовом, присваиванием) подставляется, только если параметр
 * встречается в теле ровно один раз и такой аргумент один. Именованные аргументы
 * и распаковка `...$args` не подставляются; пропущенный аргумент без значения
 * по умолчанию — ошибка вызова, такой вызов остаётся.
 */
#[VisitorMeta('Подстановка тел функций на место вызовов (инлайн)', targets: [Node\Expr\FuncCall::class])]
class CallFunctionVisitor extends BaseVisitor
{
    protected ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name || $node->isFirstClassCallable()) {
            return null;
        }

        $name  = ltrim($node->name->toString(), '\\');
        $stack = $node->getAttribute('inlineStack', []);
        $stack = is_array($stack) ? $stack : [];
        // Вызов из тела, встроенного из этой же функции, — рекурсия: не разворачиваем
        if (in_array(strtolower($name), $stack, true)) {
            return null;
        }

        $expr = $this->scopeStore->getFunctionFromGlobal($name);
        if (!$expr instanceof Node\Expr || !$this->argumentsFit($node, $expr)) {
            return null;
        }

        // заменяем в копии тела параметры на аргументы
        $clonedExpr = $this->deepClone($expr);
        $traverser  = new NodeTraverser();
        $traverser->addVisitor(new ParametersToArgsVisitor($node));

        $first = $traverser->traverse([$clonedExpr])[0] ?? null;
        if (!$first instanceof Node) {
            return null;
        }

        foreach ([$first, ...$this->findNode(Node::class, $first)] as $inner) {
            // Пометки параметров тела больше не нужны: иначе следующая подстановка
            // приняла бы эти переменные за свои параметры
            foreach (['arg_index', 'arg_default', 'arg_has_default'] as $mark) {
                $inner->setAttribute($mark, null);
            }

            if ($inner instanceof Node\Expr\FuncCall) {
                $inner->setAttribute('inlineStack', [...$stack, strtolower($name)]);
            }
        }

        return $first;
    }

    private function argumentsFit(Node\Expr\FuncCall $call, Node\Expr $body): bool
    {
        $uses = [];
        $defaults = [];
        foreach ($this->findNode(Node\Expr\Variable::class, $body) as $var) {
            $index = $var->getAttribute('arg_index');
            if (is_int($index)) {
                $uses[$index]     = ($uses[$index] ?? 0) + 1;
                $defaults[$index] = $var->getAttribute('arg_has_default') === true || $var->getAttribute('arg_default') instanceof Node;
            }
        }

        $paramCount = $body->getAttribute('param_count');
        if (is_int($paramCount) && count($call->args) > $paramCount) {
            return false; // лишние аргументы вычисляются, хотя не используются
        }

        $complex = 0;
        foreach ($call->args as $index => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name instanceof \PhpParser\Node\Identifier || $arg->byRef) {
                return false;
            }

            if (!$this->isSimple($arg->value)) {
                $complex++;
                if (($uses[$index] ?? 0) !== 1 || $complex > 1) {
                    return false;
                }
            }
        }

        return array_all($uses, fn(int $count, $index): bool => isset($call->args[$index]) || $defaults[$index]);
    }

    /** Вычисление без эффектов и без зависимости от порядка. */
    private function isSimple(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Scalar
            || $expr instanceof Node\Expr\Variable
            || $expr instanceof Node\Expr\ConstFetch
            || $expr instanceof Node\Expr\ClassConstFetch;
    }
}
