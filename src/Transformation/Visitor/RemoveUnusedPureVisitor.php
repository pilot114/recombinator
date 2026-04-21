<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет чистые вычисления, результат которых нигде не используется:
 * 1. Statement-выражения без присваивания: `$a + $b;` → удалить
 * 2. Присваивания чистого выражения переменной, которая нигде не читается: `$x = $a * 2;` → удалить
 *
 * «Чистое» = без побочных эффектов: операции над скалярами/переменными,
 * а также вызовы заведомо чистых стандартных функций.
 */
#[VisitorMeta('Удаление мёртвых чистых вычислений')]
class RemoveUnusedPureVisitor extends BaseVisitor
{
    /**
     * Стандартные PHP-функции без побочных эффектов.
     * Вызов такой функции можно удалить, если результат нигде не используется.
     *
     * @var array<string, true>
     */
    public const PURE_FUNCTIONS = [
        // Проверка типов
        'is_array' => true, 'is_string' => true, 'is_int' => true,
        'is_integer' => true, 'is_long' => true, 'is_float' => true,
        'is_double' => true, 'is_real' => true, 'is_bool' => true,
        'is_null' => true, 'is_numeric' => true, 'is_object' => true,
        'is_resource' => true, 'is_callable' => true, 'is_finite' => true,
        'is_infinite' => true, 'is_nan' => true,
        // Строки
        'strlen' => true, 'mb_strlen' => true,
        'strtolower' => true, 'strtoupper' => true, 'mb_strtolower' => true, 'mb_strtoupper' => true,
        'ucfirst' => true, 'lcfirst' => true, 'ucwords' => true,
        'trim' => true, 'ltrim' => true, 'rtrim' => true,
        'str_pad' => true, 'str_repeat' => true, 'str_replace' => true, 'str_ireplace' => true,
        'str_contains' => true, 'str_starts_with' => true, 'str_ends_with' => true,
        'substr' => true, 'mb_substr' => true,
        'strpos' => true, 'strrpos' => true, 'mb_strpos' => true,
        'sprintf' => true, 'number_format' => true, 'vsprintf' => true,
        'ord' => true, 'chr' => true, 'bin2hex' => true, 'hex2bin' => true,
        'implode' => true, 'join' => true, 'explode' => true,
        'nl2br' => true, 'htmlspecialchars' => true, 'htmlentities' => true,
        'strip_tags' => true, 'wordwrap' => true,
        'urlencode' => true, 'urldecode' => true, 'rawurlencode' => true, 'rawurldecode' => true,
        'base64_encode' => true, 'base64_decode' => true,
        'json_encode' => true, 'json_decode' => true,
        'md5' => true, 'sha1' => true, 'crc32' => true, 'hash' => true,
        // Математика
        'abs' => true, 'round' => true, 'ceil' => true, 'floor' => true,
        'max' => true, 'min' => true, 'pow' => true, 'sqrt' => true,
        'log' => true, 'log10' => true, 'log1p' => true, 'exp' => true,
        'pi' => true, 'sin' => true, 'cos' => true, 'tan' => true,
        'asin' => true, 'acos' => true, 'atan' => true, 'atan2' => true,
        'fmod' => true, 'intdiv' => true, 'hypot' => true, 'fdiv' => true,
        // Массивы
        'count' => true, 'sizeof' => true,
        'array_keys' => true, 'array_values' => true, 'array_merge' => true,
        'array_slice' => true, 'array_splice' => true, 'array_chunk' => true,
        'array_map' => true, 'array_filter' => true, 'array_reduce' => true,
        'array_unique' => true, 'array_reverse' => true, 'array_flip' => true,
        'array_combine' => true, 'array_diff' => true, 'array_intersect' => true,
        'in_array' => true, 'array_key_exists' => true, 'array_search' => true,
        // Преобразование типов
        'intval' => true, 'floatval' => true, 'strval' => true, 'boolval' => true,
        'settype' => true,
    ];
    /**
     * Переменные, которые хотя бы раз читаются в коде (не только присваиваются)
     *
     * @var array<string, true>
     */
    public static function isKnownPureFunction(string $name): bool
    {
        return isset(self::PURE_FUNCTIONS[$name]);
    }

    private array $readVars = [];

    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->readVars = $this->collectReadVars($nodes);
        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $expr = $node->expr;

        // Случай 1: чистое выражение как statement (результат выброшен, присваивания нет)
        if (!$expr instanceof Node\Expr\Assign && $this->isPure($expr)) {
            $node->setAttribute('remove', true);
            return null;
        }

        // Случай 2: присваивание чистого выражения переменной, которая нигде не читается
        if (
            $expr instanceof Node\Expr\Assign &&
            $expr->var instanceof Node\Expr\Variable &&
            is_string($expr->var->name) &&
            !isset($this->readVars[$expr->var->name]) &&
            $this->isPure($expr->expr)
        ) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    /**
     * Собирает имена переменных, которые встречаются в позиции чтения
     * (всё кроме прямой левой части Assign).
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    private function collectReadVars(array $nodes): array
    {
        $readVars = [];

        // Идентификаторы Variable-узлов, стоящих на LHS присваивания
        $assignLhsIds = [];
        foreach ($this->findNode(Node\Expr\Assign::class, $nodes) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable) {
                $assignLhsIds[spl_object_id($assign->var)] = true;
            }
        }

        foreach ($this->findNode(Node\Expr\Variable::class, $nodes) as $var) {
            if (is_string($var->name) && !isset($assignLhsIds[spl_object_id($var)])) {
                $readVars[$var->name] = true;
            }
        }

        return $readVars;
    }

    private function isPure(Node $node): bool
    {
        if ($node instanceof Node\Scalar) {
            return true;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return true;
        }
        if ($node instanceof Node\Expr\Variable) {
            return true;
        }
        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->isPure($node->left) && $this->isPure($node->right);
        }
        if ($node instanceof Node\Expr\UnaryOp || $node instanceof Node\Expr\BooleanNot) {
            return $this->isPure($node->expr);
        }
        if ($node instanceof Node\Expr\Cast) {
            return $this->isPure($node->expr);
        }
        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->key !== null && !$this->isPure($item->key)) {
                    return false;
                }
                if (!$this->isPure($item->value)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            return $this->isPure($node->var) && ($node->dim === null || $this->isPure($node->dim));
        }
        if ($node instanceof Node\Expr\Ternary) {
            return $this->isPure($node->cond)
                && ($node->if === null || $this->isPure($node->if))
                && $this->isPure($node->else);
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = strtolower($node->name->toString());
            if (isset(self::PURE_FUNCTIONS[$name])) {
                foreach ($node->args as $arg) {
                    if ($arg instanceof Node\Arg && !$this->isPure($arg->value)) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }
}
