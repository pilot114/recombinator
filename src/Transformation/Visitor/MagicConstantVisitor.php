<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Подставляет значения магических констант, которые зависят только от места в коде:
 *
 *   class Doctrine_ORM_UnitOfWork { function persist() { trigger(__METHOD__); } }
 *   →  trigger('Doctrine_ORM_UnitOfWork::persist')
 *
 * - __CLASS__ — в классе, интерфейсе или enum (не в трейте: там это класс-пользователь;
 *   не в анонимном классе: имя генерируется);
 * - __METHOD__, __FUNCTION__ — в методе или функции, но не в замыкании (там `{closure}`);
 * - __NAMESPACE__ — пустая строка, если в программе нет namespace.
 *
 * Значение фиксировано местом объявления: метод предка, вызванный у наследника, даёт
 * имя предка, — поэтому подстановка не меняет смысла и при дальнейшем встраивании.
 * __DIR__ и __FILE__ зависят от того, где лежит файл, — их подставляет
 * TargetEnvironmentVisitor по описанию сервера; __LINE__ не трогается.
 */
#[VisitorMeta('Подстановка __CLASS__, __METHOD__, __FUNCTION__, __NAMESPACE__', targets: [Node\Scalar\MagicConst\Class_::class, Node\Scalar\MagicConst\Method::class, Node\Scalar\MagicConst\Function_::class, Node\Scalar\MagicConst\Namespace_::class])]
class MagicConstantVisitor extends BaseVisitor
{
    private bool $namespaced = false;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->namespaced = array_any($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Namespace_);

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Scalar\MagicConst) {
            return null;
        }

        $value = match (true) {
            $node instanceof Node\Scalar\MagicConst\Class_ => $this->className($node),
            $node instanceof Node\Scalar\MagicConst\Method => $this->method($node, true),
            $node instanceof Node\Scalar\MagicConst\Function_ => $this->method($node, false),
            $node instanceof Node\Scalar\MagicConst\Namespace_ => $this->namespaced ? null : '',
            default => null,
        };

        // magicConstant: строка — значение константы, а не callable (см. StaticMethodExtractVisitor)
        return $value === null ? null : new Node\Scalar\String_($value, ['magicConstant' => true]);
    }

    private function className(Node $node): ?string
    {
        $class = $this->enclosing($node, Node\Stmt\ClassLike::class);

        return $class instanceof Node\Stmt\ClassLike && !$class instanceof Node\Stmt\Trait_ && $class->name instanceof Node\Identifier
            ? $class->name->toString()
            : null;
    }

    private function method(Node $node, bool $qualified): ?string
    {
        $function = $this->enclosing($node, Node\FunctionLike::class);
        if ($function instanceof Node\Stmt\Function_) {
            return $function->name->toString();
        }

        if (!$function instanceof Node\Stmt\ClassMethod) {
            return null; // замыкание, стрелочная функция или код верхнего уровня
        }

        if (!$qualified) {
            return $function->name->toString();
        }

        $class = $this->className($function);

        return $class === null ? null : $class . '::' . $function->name->toString();
    }

    /**
     * @template T of Node
     * @param  class-string<T> $type
     * @return T|null
     */
    private function enclosing(Node $node, string $type): ?Node
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof $type) {
                return $current;
            }

            // Ближайшая функция: для __CLASS__ ищем дальше, для __METHOD__ это и есть ответ
            if ($type === Node\Stmt\ClassLike::class && $current instanceof Node\Stmt\Function_) {
                return null;
            }
        }

        return null;
    }
}
