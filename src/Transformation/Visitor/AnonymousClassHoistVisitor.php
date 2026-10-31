<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Выносит анонимный класс в именованный:
 *
 *   $this->tip = new class($middleware, $next) implements RequestHandlerInterface { … };
 *   →
 *   class Slim_MiddlewareDispatcher_anonymous_1 implements RequestHandlerInterface { … }
 *   $this->tip = new Slim_MiddlewareDispatcher_anonymous_1($middleware, $next);
 *
 * Анонимный класс не захватывает окружение (значения приходят через конструктор),
 * у него своя область видимости — перенос не меняет смысла. Имя нужно правилам по
 * всей программе: реализации интерфейса известны, его проверки можно заменить
 * (InterfaceTypeCheckVisitor). Отличается только имя класса в get_class().
 *
 * Объявление ставится сразу после инструкции верхнего уровня, в которой был класс.
 */
#[VisitorMeta('Анонимные классы → именованные', targets: [Node\Expr\New_::class])]
class AnonymousClassHoistVisitor extends BaseVisitor
{
    /** @var array<int, list<Node\Stmt\Class_>> spl_object_id инструкции верхнего уровня → вынесенные классы */
    private array $hoisted = [];

    /** @var array<string, true> */
    private array $names = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->hoisted = [];
        $this->names   = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->names[$class->name->toLowerString()] = true;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Expr\New_ || !$node->class instanceof Node\Stmt\Class_ || $node->class->name instanceof \PhpParser\Node\Identifier) {
            return parent::leaveNode($node);
        }

        $top = $node;
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            $top = $parent;
        }

        if (!$top instanceof Node\Stmt || !in_array($top, $this->ast, true)) {
            return null;
        }

        $owner = $this->owner($node);
        $index = 1;
        while (isset($this->names[strtolower($owner . '_anonymous_' . $index)])) {
            $index++;
        }

        $name                             = $owner . '_anonymous_' . $index;
        $this->names[strtolower($name)]   = true;
        $class                            = $node->class;
        $class->name                      = new Node\Identifier($name);
        $this->hoisted[spl_object_id($top)][] = $class;

        return new Node\Expr\New_(new Node\Name($name), $node->args, $node->getAttributes());
    }

    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        if ($this->hoisted === []) {
            return null;
        }

        $result = [];
        foreach ($nodes as $node) {
            $result[] = $node;
            array_push($result, ...$this->hoisted[spl_object_id($node)] ?? []);
        }

        return $result;
    }

    /** Имя объемлющего класса или функции — для читаемого имени. */
    private function owner(Node $node): string
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt\ClassLike && $parent->name instanceof Node\Identifier) {
                return $parent->name->toString();
            }

            if ($parent instanceof Node\Stmt\Function_) {
                return $parent->name->toString();
            }
        }

        return 'Main';
    }
}
