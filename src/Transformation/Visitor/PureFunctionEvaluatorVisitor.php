<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Symfony\Component\Process\Process;

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
#[VisitorMeta('Вычисление чистых функций с константными аргументами')]
class PureFunctionEvaluatorVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Function_> */
    private array $pureFunctions = [];

    /** @var array<string, mixed> */
    private array $knownVars = [];

    private readonly StandardPrinter $printer;

    private const int TIMEOUT = 3;
    private const string MEMORY_LIMIT = '32M';

    public function __construct()
    {
        $this->printer = new StandardPrinter();
    }

    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->pureFunctions = [];
        $this->knownVars     = [];

        // Шаг 1: собрать переменные с константными значениями (скаляры + константные массивы)
        foreach ($this->findNode(Node\Stmt\Expression::class) as $stmt) {
            if (
                $stmt->expr instanceof Node\Expr\Assign &&
                $stmt->expr->var instanceof Node\Expr\Variable &&
                is_string($stmt->expr->var->name)
            ) {
                try {
                    $this->knownVars[$stmt->expr->var->name] = $this->resolveToValue($stmt->expr->expr);
                } catch (\RuntimeException) {
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

    public function enterNode(Node $node): ?Node
    {
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
                $resolvedArgs[] = $this->resolveToValue($arg->value);
            }
        } catch (\RuntimeException) {
            return null;
        }

        $result = $this->runFunction($this->pureFunctions[$funcName], $resolvedArgs);
        return $result !== null ? $this->valueToNode($result) : null;
    }

    private function isFunctionPure(Node\Stmt\Function_ $fn): bool
    {
        $stmts = $fn->stmts;

        if (!empty($this->findNode(Node\Stmt\Echo_::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Expr\Print_::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Stmt\Global_::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Stmt\Static_::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Expr\MethodCall::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Expr\StaticCall::class, $stmts))) {
            return false;
        }
        if (!empty($this->findNode(Node\Expr\New_::class, $stmts))) {
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
            if (!RemoveUnusedPureVisitor::isKnownPureFunction($name) && !isset($this->pureFunctions[$name])) {
                return false;
            }
        }

        return true;
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
                default => throw new \RuntimeException("Unknown const: {$node->name}"),
            };
        }
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if (array_key_exists($node->name, $this->knownVars)) {
                return $this->knownVars[$node->name];
            }
            throw new \RuntimeException("Unknown variable: \${$node->name}");
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
        $argStrs = array_map(fn($v) => var_export($v, true), $resolvedArgs);
        $call    = $fnName . '(' . implode(', ', $argStrs) . ')';

        $code = "<?php\n" . $fnCode . "\necho json_encode(" . $call . ');';

        $tmp = tempnam(sys_get_temp_dir(), 'pfev_');
        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $code);

        try {
            $proc = new Process(
                ['php', '-d', 'memory_limit=' . self::MEMORY_LIMIT, $tmp],
                timeout: self::TIMEOUT
            );
            $proc->run();

            if (!$proc->isSuccessful() || $proc->getOutput() === '') {
                return null;
            }

            return json_decode($proc->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
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
