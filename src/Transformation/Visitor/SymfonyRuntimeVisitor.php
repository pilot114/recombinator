<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Include_;

/**
 * Вход приложения на Symfony Runtime после встраивания vendor/autoload_runtime.php:
 *
 *   if (true === (require_once __DIR__ . '/autoload.php') || empty($_SERVER['SCRIPT_FILENAME'])) {
 *   } else {
 *       $app = require $_SERVER['SCRIPT_FILENAME'];   // входной файл ещё раз — ради его return
 *       …
 *   }
 *   return function (array $context) { return new Kernel(…); };
 *   →
 *   require_once __DIR__ . '/autoload.php';
 *   if (false || empty($_SERVER['SCRIPT_FILENAME'])) {
 *   } else {
 *       $app = function (array $context) { return new Kernel(…); };
 *       …
 *   }
 *   return function (array $context) { … };
 *
 * Повторный require входного файла выполнил бы встроенную программу целиком второй раз
 * (повторные объявления классов — Fatal). В исходной программе он только возвращает
 * замыкание: autoload_runtime.php второй раз не подключается (require_once). Поэтому
 * require заменяется завершающим return входного файла.
 *
 * Автозагрузчик Composer подключается здесь впервые и возвращает объект ClassLoader,
 * поэтому `true === (require_once …)` ложно. Подключение выносится отдельной
 * инструкцией перед if — её FileMapIncludeVisitor заменит файлами автозагрузки
 * (функции, полифилы), как и обычный require автозагрузчика.
 */
#[VisitorMeta('Вход Symfony Runtime: повторный require входного файла → его замыкание', targets: [Include_::class])]
class SymfonyRuntimeVisitor extends BaseVisitor
{
    /** Завершающий return входного файла: замыкание приложения */
    private Node\Expr\Closure|Node\Expr\ArrowFunction|null $app = null;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $last      = end($nodes);
        $this->app = $last instanceof Node\Stmt\Return_ && ($last->expr instanceof Node\Expr\Closure || $last->expr instanceof Node\Expr\ArrowFunction)
            ? $last->expr
            : null;

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Include_ || !str_ends_with((string) $node->getAttribute('sourceFile'), 'autoload_runtime.php')) {
            return null;
        }

        if (in_array($node->type, [Include_::TYPE_REQUIRE, Include_::TYPE_INCLUDE], true) && $this->app !== null && $this->isScriptFilename($node->expr)) {
            return $this->deepClone($this->app);
        }

        $check = $node->getAttribute('parent');
        $stmt  = $check instanceof Node\Expr\BinaryOp\Identical ? $this->topLevelIfEvaluatingFirst($check) : null;
        if ($node->type === Include_::TYPE_REQUIRE_ONCE && $check instanceof Node\Expr\BinaryOp\Identical && $stmt instanceof Node\Stmt\If_ && $this->isTrue($check->left === $node ? $check->right : $check->left)) {
            $check->setAttribute('replace', new Node\Expr\ConstFetch(new Node\Name('false')));
            $stmt->setAttribute('replace', [new Node\Stmt\Expression($node), $stmt]);
        }

        return null;
    }

    private function isScriptFilename(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ArrayDimFetch
            && $expr->var instanceof Node\Expr\Variable && $expr->var->name === '_SERVER'
            && $expr->dim instanceof Node\Scalar\String_ && $expr->dim->value === 'SCRIPT_FILENAME';
    }

    private function isTrue(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'true';
    }

    /**
     * if верхнего уровня, в условии которого сравнение вычисляется первым (левый операнд
     * `||`/`&&`): вынос подключения перед if не меняет порядок эффектов.
     */
    private function topLevelIfEvaluatingFirst(Node\Expr $expr): ?Node\Stmt\If_
    {
        $child  = $expr;
        $parent = $expr->getAttribute('parent');
        while (($parent instanceof Node\Expr\BinaryOp\BooleanOr || $parent instanceof Node\Expr\BinaryOp\BooleanAnd) && $parent->left === $child) {
            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return $parent instanceof Node\Stmt\If_ && $parent->cond === $child && $parent->getAttribute('parent') === null ? $parent : null;
    }
}
