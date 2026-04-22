<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет определения классов, на которые нет ссылок извне их собственного тела.
 *
 * Полезно после ConstructorAndMethodsVisitor / PropertyAccessVisitor / ConstClassVisitor —
 * когда все use-site'ы класса инлайнены, определение становится мёртвым кодом.
 *
 * Внутренние самоссылки (`class Point { function f(Point $x) }`) игнорируются —
 * они учитываются только если класс упоминается где-то вне своего тела.
 *
 * Динамические ссылки (`new $cls`, строковые callable) не отслеживаются —
 * такие классы могут быть удалены ошибочно; в нынешнем пайплайне они и так
 * не инлайнятся, поэтому это не практическая проблема.
 */
#[VisitorMeta('Удаление неиспользуемых классов')]
class RemoveUnusedClassVisitor extends BaseVisitor
{
    /** Имена, которые встречаются в коде вне определений одноимённых классов. */
    /** @var array<string, true> */
    private array $externalReferences = [];

    /** Зарезервированные / built-in имена типов — их не считаем ссылками на классы. */
    private const RESERVED = [
        'self' => true, 'parent' => true, 'static' => true, 'this' => true,
        'null' => true, 'bool' => true, 'int' => true, 'float' => true,
        'string' => true, 'array' => true, 'object' => true, 'mixed' => true,
        'void' => true, 'never' => true, 'iterable' => true, 'callable' => true,
        'true' => true, 'false' => true, 'numeric' => true, 'resource' => true,
    ];

    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->externalReferences = [];
        $this->scan($nodes, []);
        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Class_ || $node->name === null) {
            return null;
        }

        $name = strtolower($node->name->toString());
        if (!isset($this->externalReferences[$name])) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    /**
     * Рекурсивный обход со стеком вложенных классов.
     *
     * @param mixed         $nodes
     * @param array<string> $enclosing
     */
    private function scan(mixed $nodes, array $enclosing): void
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $pushed = false;
            if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                $enclosing[] = strtolower($node->name->toString());
                $pushed = true;
            }

            if ($node instanceof Node\Name) {
                $lower = strtolower($node->toString());
                if (!isset(self::RESERVED[$lower]) && !in_array($lower, $enclosing, true)) {
                    $this->externalReferences[$lower] = true;
                }
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $this->scan($node->$sub, $enclosing);
            }

            if ($pushed) {
                array_pop($enclosing);
            }
        }
    }
}
