<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Support\Sandbox;
use Recombinator\Support\SandboxError;
use Recombinator\Support\SandboxRunner;

/**
 * Вычисляет чистые пользовательские функции в compile-time, если все аргументы известны.
 *
 * Функция считается чистой если:
 * - нет echo/print, global/static
 * - нет обращений к суперглобальным переменным
 * - нет вызовов методов/статических методов
 * - все внутренние вызовы функций — либо к известным чистым стандартным функциям,
 *   либо к другим чистым пользовательским функциям из этого же файла
 *
 * Аргументы считаются известными если они — скаляры, константы, константные массивы
 * или переменные, чьё значение (тоже константное) известно из предшествующих присваиваний в коде.
 */
#[VisitorMeta('Вычисление чистых функций с константными аргументами', targets: [Node\Stmt\Function_::class])]
class PureFunctionEvaluatorVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Function_> */
    private array $pureFunctions = [];

    /** @var array<string, mixed> */
    private array $knownVars = [];

    private readonly StandardPrinter $printer;

    private const int TIMEOUT = 3;

    private const string MEMORY_LIMIT = '32M';

    /** Глубина функций и классов вокруг текущего узла. */
    private int $depth = 0;

    public function __construct()
    {
        $this->printer = new StandardPrinter();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->pureFunctions = [];
        $this->knownVars     = [];

        // Шаг 1: собрать переменные с константными значениями (скаляры + константные массивы).
        // Только верхний уровень и единственное присваивание: `$id = []` в одном методе —
        // не значение `$id` в другом
        $this->depth = 0;
        $counts      = ObjectMethodInlinerVisitor::topLevelAssignCounts($nodes);
        foreach ($nodes as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Expression &&
                $stmt->expr instanceof Node\Expr\Assign &&
                $stmt->expr->var instanceof Node\Expr\Variable &&
                is_string($stmt->expr->var->name) &&
                ($counts[$stmt->expr->var->name] ?? 0) === 1
            ) {
                try {
                    $this->knownVars[$stmt->expr->var->name] = $this->resolveStrict($stmt->expr->expr);
                } catch (\RuntimeException | \Error) {
                    // не константное выражение — пропускаем
                }
            }
        }

        // Шаг 2: собрать чистые функции
        foreach ($this->findNode(Node\Stmt\Function_::class) as $fn) {
            if ($this->isFunctionPure($fn)) {
                $this->pureFunctions[strtolower((string) $fn->name)] = $fn;
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            $this->depth--;
        }

        return parent::leaveNode($node);
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            $this->depth++;
        }

        if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name) {
            return null;
        }

        $funcName = strtolower($node->name->toString());
        if (!isset($this->pureFunctions[$funcName])) {
            return null;
        }

        // Пытаемся разрешить все аргументы в конкретные PHP-значения
        try {
            $resolvedArgs = [];
            foreach ($node->args as $arg) {
                if (!$arg instanceof Node\Arg) {
                    return null; // spread/variadic — пропускаем
                }

                $resolvedArgs[] = $this->resolveStrict($arg->value);
            }
        } catch (\RuntimeException | \Error) {
            return null;
        }

        $result = $this->runFunction($this->pureFunctions[$funcName], $resolvedArgs);
        return $result !== null ? $this->valueToNode($result) : null;
    }

    private function isFunctionPure(Node\Stmt\Function_ $fn): bool
    {
        $stmts = $fn->stmts;

        if ($this->findNode(Node\Stmt\Echo_::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Expr\Print_::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Stmt\Global_::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Stmt\Static_::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Expr\MethodCall::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Expr\StaticCall::class, $stmts) !== []) {
            return false;
        }

        if ($this->findNode(Node\Expr\New_::class, $stmts) !== []) {
            return false;
        }

        $superglobals = ['_GET', '_POST', '_SESSION', '_COOKIE', '_SERVER', '_ENV', '_FILES', '_REQUEST', 'GLOBALS'];
        foreach ($this->findNode(Node\Expr\Variable::class, $stmts) as $var) {
            if (is_string($var->name) && in_array($var->name, $superglobals, true)) {
                return false;
            }
        }

        // Все вызовы внутри тела — только к известным чистым функциям
        foreach ($this->findNode(Node\Expr\FuncCall::class, $stmts) as $call) {
            if (!$call->name instanceof Node\Name) {
                return false; // динамический вызов
            }

            $name = strtolower($call->name->toString());
            if (!Sandbox::isEffectFree($name) && !isset($this->pureFunctions[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Как resolveToValue, но предупреждение PHP (например, конкатенация массива
     * со строкой) считается неконстантным выражением, а не даёт неверное значение.
     *
     * @throws \RuntimeException
     */
    private function resolveStrict(Node $node): mixed
    {
        set_error_handler(static function (int $errno, string $message): never {
            throw new \RuntimeException($message);
        });
        try {
            return $this->resolveToValue($node);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Рекурсивно разрешает AST-узел в конкретное PHP-значение.
     *
     * @throws \RuntimeException если узел не является константным выражением
     */
    private function resolveToValue(Node $node): mixed
    {
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\Float_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => throw new \RuntimeException('Unknown const: ' . $node->name),
            };
        }

        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            // Значения известны только для переменных верхнего уровня
            if ($this->depth === 0 && array_key_exists($node->name, $this->knownVars)) {
                return $this->knownVars[$node->name];
            }

            throw new \RuntimeException('Unknown variable: $' . $node->name);
        }

        if ($node instanceof Node\Expr\Array_) {
            $arr = [];
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                $val = $this->resolveToValue($item->value);
                if ($item->key !== null) {
                    $arr[$this->resolveToValue($item->key)] = $val;
                } else {
                    $arr[] = $val;
                }
            }

            return $arr;
        }

        // Арифметические и логические бинарные операции над константными операндами
        if ($node instanceof Node\Expr\BinaryOp) {
            $l = $this->resolveToValue($node->left);
            $r = $this->resolveToValue($node->right);
            return match (true) {
                $node instanceof Node\Expr\BinaryOp\Plus       => $l + $r,
                $node instanceof Node\Expr\BinaryOp\Minus      => $l - $r,
                $node instanceof Node\Expr\BinaryOp\Mul        => $l * $r,
                $node instanceof Node\Expr\BinaryOp\Div        => $r != 0 ? $l / $r : throw new \RuntimeException('Division by zero'),
                $node instanceof Node\Expr\BinaryOp\Mod        => $l % $r,
                $node instanceof Node\Expr\BinaryOp\Pow        => $l ** $r,
                $node instanceof Node\Expr\BinaryOp\Concat     => $l . $r,
                $node instanceof Node\Expr\BinaryOp\BooleanAnd => $l && $r,
                $node instanceof Node\Expr\BinaryOp\BooleanOr  => $l || $r,
                default                                        => throw new \RuntimeException('Unsupported op: ' . $node::class),
            };
        }

        // Унарные операции
        if ($node instanceof Node\Expr\UnaryMinus) {
            return -$this->resolveToValue($node->expr);
        }

        if ($node instanceof Node\Expr\UnaryPlus) {
            return +$this->resolveToValue($node->expr);
        }

        if ($node instanceof Node\Expr\BooleanNot) {
            return !$this->resolveToValue($node->expr);
        }

        throw new \RuntimeException("Cannot resolve: " . $node::class);
    }

    /**
     * Выполняет функцию в изолированном subprocess с переданными аргументами.
     *
     * @param  array<mixed> $resolvedArgs
     */
    private function runFunction(Node\Stmt\Function_ $fn, array $resolvedArgs): mixed
    {
        $fnCode  = $this->printer->prettyPrint([$fn]);
        $fnName  = $fn->name->name;
        $argStrs = array_map(fn($v): string => var_export($v, true), $resolvedArgs);
        $call    = $fnName . '(' . implode(', ', $argStrs) . ')';

        $code = "<?php\n" . $fnCode . "\necho json_encode(" . $call . ');';

        $output = SandboxRunner::shared()->run($code, self::TIMEOUT, self::MEMORY_LIMIT);
        if ($output instanceof SandboxError || $output === '') {
            return null;
        }

        try {
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function valueToNode(mixed $value): Node
    {
        if ($value === null) {
            return new Node\Expr\ConstFetch(new Node\Name('null'));
        }

        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'));
        }

        if (is_int($value)) {
            return new Node\Scalar\Int_($value);
        }

        if (is_float($value)) {
            return new Node\Scalar\Float_($value);
        }

        if (is_string($value)) {
            return new Node\Scalar\String_($value);
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $k => $v) {
                $items[] = new Node\ArrayItem(
                    $this->valueToNode($v),
                    is_string($k) ? new Node\Scalar\String_($k) : new Node\Scalar\Int_($k)
                );
            }

            return new Node\Expr\Array_($items);
        }

        return new Node\Expr\ConstFetch(new Node\Name('null'));
    }
}
