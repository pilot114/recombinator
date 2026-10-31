<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Объявляет исходные имена встроенных классов алиасами плоских:
 *
 *   foreach (['Faker_Generator' => 'Faker\Generator', ...] as $flat => $original) {
 *       ... class_alias($flat, $original, false);
 *   }
 *
 * Нужно, только если в программе остался запасной автозагрузчик Composer
 * (FileMapIncludeVisitor::runtimeAutoload): класс из vendor, загруженный по имени,
 * собранному во время выполнения, проверяет типы по исходным именам
 * (`Base::__construct(Faker\Generator $g)`), а проверка типа автозагрузку не вызывает.
 *
 * Запускается один раз после схождения: строки с именами в карте иначе считались бы
 * ссылками на классы и мешали удалению недостижимых.
 */
#[VisitorMeta('Алиасы исходных имён встроенных классов для кода из vendor', targets: [Node\Stmt\ClassLike::class])]
class OriginalClassAliasVisitor extends BaseVisitor
{
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $nodes = array_values($nodes);
        $at    = array_find_key($nodes, static fn(Node $node): bool => $node->getAttribute('runtimeAutoload') === true);

        if ($at === null) {
            return null;
        }

        $items = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            $original = $class->getAttribute('originalName');
            if (is_string($original) && $class->name instanceof Node\Identifier && $original !== $class->name->toString()) {
                $items[] = new Node\ArrayItem(new Node\Scalar\String_($original), new Node\Scalar\String_($class->name->toString()));
            }
        }

        if ($items === []) {
            return null;
        }

        $exists = static fn(string $function): Node\Expr\FuncCall => new Node\Expr\FuncCall(
            new Node\Name($function),
            [new Node\Arg(new Node\Expr\Variable('flat')), new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('false')))],
        );
        $alias = new Node\Stmt\Foreach_(
            new Node\Expr\Array_($items),
            new Node\Expr\Variable('original'),
            [
                'keyVar' => new Node\Expr\Variable('flat'),
                'stmts'  => [
                    new Node\Stmt\If_(
                        new Node\Expr\BinaryOp\BooleanOr(
                            new Node\Expr\BinaryOp\BooleanOr($exists('class_exists'), $exists('interface_exists')),
                            $exists('trait_exists'),
                        ),
                        ['stmts' => [new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Name('class_alias'), [
                            new Node\Arg(new Node\Expr\Variable('flat')),
                            new Node\Arg(new Node\Expr\Variable('original')),
                            new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('false'))),
                        ]))]],
                    ),
                ],
            ],
        );

        array_splice($nodes, $at, 0, [$alias]);

        return $nodes;
    }
}
