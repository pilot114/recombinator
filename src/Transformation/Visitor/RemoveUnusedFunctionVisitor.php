<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Core\Config;
use Recombinator\Support\ExternalCode;

/**
 * Удаляет определения функций, которые нигде не вызываются.
 *
 * Используется: вызов `f()` (в том числе `f(...)`), строка `'f'` (callable,
 * `function_exists('f')`), упоминание во внешнем коде — файлах проекта, не вошедших
 * в программу. Рекурсивная функция остаётся (вызов в теле — тоже использование).
 *
 * Пока есть встраиваемый include, ничего не удаляется; невстраиваемые include не
 * учитываются — то же соглашение, что у RemoveUnusedMethodVisitor. Имя, собранное
 * из частей (`'f' . $x`), не видно.
 */
#[VisitorMeta('Удаление неиспользуемых функций')]
class RemoveUnusedFunctionVisitor extends BaseVisitor
{
    /** @var array<string, true> */
    private array $used = [];

    private bool $skip = false;

    private readonly Config $config;

    /** @param array<string, string> $fileMap файлы проекта (путь → код) */
    public function __construct(?Config $config = null, private readonly array $fileMap = [])
    {
        $this->config = $config ?? new Config();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->used = [];
        $this->skip = $this->hasPendingIncludes();
        if ($this->skip) {
            return null;
        }

        $classes = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $this->used[strtolower(ltrim($node->name->toString(), '\\'))] = true;
            } elseif ($node instanceof Node\Scalar\String_) {
                $this->used[strtolower(ltrim($node->value, '\\'))] = true;
            } elseif ($node instanceof Node\Stmt\ClassLike && $node->name instanceof Node\Identifier) {
                $classes[$node->name->toLowerString()] = true;
            }
        }

        if ($this->fileMap !== []) {
            $this->used += ExternalCode::forProgram($this->fileMap, $classes, $this->ast)->names();
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->skip || !$node instanceof Node\Stmt\Function_) {
            return null;
        }

        $name = $node->name->toLowerString();
        if (!isset($this->used[$name]) && !$this->config->isProtected($name)) {
            $node->setAttribute('remove', true);
        }

        return null;
    }
}
