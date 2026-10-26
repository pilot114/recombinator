<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет вызовы `spl_autoload_register(...)`.
 *
 * После инлайнинга классов через UseImportVisitor автозагрузчик становится
 * мёртвым кодом: все классы уже присутствуют в AST напрямую. Исключение —
 * автозагрузчик, который FileMapIncludeVisitor ставит для имён, собранных во время
 * выполнения (атрибут runtimeAutoload).
 */
#[VisitorMeta('Удаление spl_autoload_register (мёртвый код после инлайнинга)')]
class RemoveSplAutoloadVisitor extends BaseVisitor
{
    public function enterNode(Node $node): ?Node
    {
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\FuncCall
            && $node->expr->name instanceof Node\Name
            && strtolower($node->expr->name->toString()) === 'spl_autoload_register'
            && $node->getAttribute('runtimeAutoload') !== true
        ) {
            $node->setAttribute('remove', true);
        }

        return null;
    }
}
