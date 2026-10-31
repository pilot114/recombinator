<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use Recombinator\Core\TargetEnvironment;

/**
 * Удаляет `require` файлов проекта, которые на целевом сервере ничего не сделают:
 *
 *   require_once __DIR__ . '/../vendor/symfony/polyfill-mbstring/bootstrap.php';
 *
 * Бутстрап полифила проверяет версию PHP и расширения и объявляет функции под
 * `if (!function_exists(...))`; FileMapIncludeVisitor такой файл не встраивает
 * (`return` на верхнем уровне). Здесь файл выполняется «в уме» по описанию сервера
 * (TargetEnvironment): условия сворачиваются как в TargetEnvironmentVisitor; `return`
 * и `return require 'другой файл'` разбираются; если до конца не встретилось ни одного
 * объявления и ни одной инструкции с эффектом, подключение лишнее.
 *
 * Любое неизвестное условие или инструкция — файл считается полезным и остаётся.
 */
#[VisitorMeta('Удаление require полифилов, лишних на целевом сервере', targets: [Node\Expr\Include_::class])]
class PolyfillRequireVisitor extends BaseVisitor
{
    private const int MAX_DEPTH = 8;

    /** @var array<string, string> нормализованный путь → ключ карты файлов */
    private array $paths = [];

    /** @var array<string, bool> ключ файла → ничего не делает */
    private array $noop = [];

    /** @param array<string, string> $fileMap файлы проекта (путь → код) */
    public function __construct(
        private readonly TargetEnvironment $environment,
        private readonly array $fileMap = [],
        private readonly string $entry = '',
    ) {
        foreach (array_keys($fileMap) as $key) {
            $this->paths[$this->normalize((string) $key)] = (string) $key;
        }
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->environment->isEmpty() || !$node instanceof Node\Stmt\Expression || !$node->expr instanceof Node\Expr\Include_) {
            return null;
        }

        $file = $this->resolve($node->expr->expr, dirname($this->entry));
        if ($file !== null && $this->isNoop($file, 0)) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    private function isNoop(string $file, int $depth): bool
    {
        if (isset($this->noop[$file])) {
            return $this->noop[$file];
        }

        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        $this->noop[$file] = false; // на время разбора: циклическое подключение — не трогаем
        $ast               = new ParserFactory()->createForNewestSupportedVersion()->parse($this->fileMap[$file]) ?? [];
        $result            = $this->run($ast, dirname($file), $depth);

        return $this->noop[$file] = $result !== 'effect';
    }

    /**
     * Выполняет инструкции «в уме».
     *
     * @param  array<Node\Stmt> $stmts
     * @return 'continue'|'return'|'effect'
     */
    private function run(array $stmts, string $dir, int $depth): string
    {
        foreach ($stmts as $stmt) {
            $outcome = match (true) {
                $stmt instanceof Node\Stmt\Use_, $stmt instanceof Node\Stmt\Nop, $stmt instanceof Node\Stmt\Declare_ => 'continue',
                $stmt instanceof Node\Stmt\If_ => $this->runIf($stmt, $dir, $depth),
                $stmt instanceof Node\Stmt\Return_ => $stmt->expr instanceof Node\Expr\Include_
                    ? $this->runInclude($stmt->expr, $dir, $depth, 'return')
                    : (!$stmt->expr instanceof \PhpParser\Node\Expr || $this->isLiteral($stmt->expr) ? 'return' : 'effect'),
                $stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Include_ => $this->runInclude($stmt->expr, $dir, $depth, 'continue'),
                default => 'effect',
            };
            if ($outcome !== 'continue') {
                return $outcome;
            }
        }

        return 'continue';
    }

    /** @return 'continue'|'return'|'effect' */
    private function runIf(Node\Stmt\If_ $if, string $dir, int $depth): string
    {
        foreach ([[$if->cond, $if->stmts], ...array_map(static fn(Node\Stmt\ElseIf_ $e): array => [$e->cond, $e->stmts], $if->elseifs)] as [$cond, $stmts]) {
            $value = $this->evaluate($cond);
            if ($value === null) {
                return 'effect';
            }

            if ($value) {
                return $this->run($stmts, $dir, $depth);
            }
        }

        return $if->else instanceof Node\Stmt\Else_ ? $this->run($if->else->stmts, $dir, $depth) : 'continue';
    }

    /** @return 'continue'|'return'|'effect' */
    private function runInclude(Node\Expr\Include_ $include, string $dir, int $depth, string $after): string
    {
        $file = $this->resolve($include->expr, $dir);

        return $file !== null && $this->isNoop($file, $depth + 1) ? $after : 'effect';
    }

    /** Условие по описанию сервера: true/false или null, если оно неизвестно. */
    private function evaluate(Node\Expr $cond): ?bool
    {
        $folded = new NodeTraverser(new NodeConnectingVisitor())->traverse([new Node\Stmt\Expression($this->deepClone($cond))]);
        $folded = new NodeTraverser(new TargetEnvironmentVisitor($this->environment, $this->fileMap))->traverse($folded);

        $expr   = $folded[0] instanceof Node\Stmt\Expression ? $folded[0]->expr : null;

        $value = $expr instanceof Node\Expr ? $this->value($expr) : null;

        return is_bool($value) ? $value : null;
    }

    private function value(Node\Expr $expr): int|string|bool|null
    {
        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\BooleanOr) {
            $left = $this->value($expr->left);
            $and  = $expr instanceof Node\Expr\BinaryOp\BooleanAnd;
            if (is_bool($left) && $left !== $and) {
                return $left; // false && … / true || …
            }

            $right = $this->value($expr->right);

            return is_bool($left) && is_bool($right) ? ($and ? $left && $right : $left || $right) : null;
        }

        if ($expr instanceof Node\Expr\BinaryOp) {
            $left  = $this->value($expr->left);
            $right = $this->value($expr->right);
            if ($left === null || $right === null) {
                return null;
            }

            return match (true) {
                $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual => $left >= $right,
                $expr instanceof Node\Expr\BinaryOp\Greater => $left > $right,
                $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual => $left <= $right,
                $expr instanceof Node\Expr\BinaryOp\Smaller => $left < $right,
                $expr instanceof Node\Expr\BinaryOp\Identical => $left === $right,
                $expr instanceof Node\Expr\BinaryOp\NotIdentical => $left !== $right,
                default => null,
            };
        }

        return match (true) {
            $expr instanceof Node\Expr\BooleanNot => is_bool($inner = $this->value($expr->expr)) ? !$inner : null,
            $expr instanceof Node\Expr\ConstFetch && in_array($expr->name->toLowerString(), ['true', 'false'], true) => $expr->name->toLowerString() === 'true',
            $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\String_ => $expr->value,
            default => null,
        };
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Scalar || $expr instanceof Node\Expr\ConstFetch;
    }

    /** `__DIR__ . '/x/y.php'` (и цепочки конкатенаций) относительно каталога файла → ключ карты. */
    private function resolve(Node\Expr $expr, string $dir): ?string
    {
        $path = $this->pathOf($expr, $dir);

        return $path === null ? null : $this->paths[$this->normalize($path)] ?? null;
    }

    private function pathOf(Node\Expr $expr, string $dir): ?string
    {
        return match (true) {
            $expr instanceof Node\Scalar\MagicConst\Dir => $dir,
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Expr\BinaryOp\Concat => ($left = $this->pathOf($expr->left, $dir)) === null || ($right = $this->pathOf($expr->right, $dir)) === null
                ? null
                : $left . $right,
            default => null,
        };
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
