<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Сворачивает проверки существования класса с литеральным именем, когда известны
 * все классы программы (после встраивания зависимостей из карты файлов):
 *
 *   class_exists('Nyholm_Psr7_Factory_Psr17Factory')  → true   (объявлен в AST)
 *   class_exists('Laminas\Diactoros\ResponseFactory') → false  (пакета нет в карте)
 *
 * Заодно `'Cls'::m()` → `Cls::m()` и `new ('Cls')` → `new Cls`: такие выражения
 * появляются после раскрутки цикла по списку имён классов и подстановки констант,
 * а другие визиторы понимают только имя класса.
 *
 * Отрицательный ответ даётся только для имён с namespace: UseImportVisitor уже
 * переименовал бы такой класс, если бы нашёл его файл. Имя без namespace может
 * объявить файл, который FileMapIncludeVisitor встроит позже. Встроенные классы
 * считаются существующими, только если они из ядра PHP — расширения на сервере
 * могут отличаться.
 */
#[VisitorMeta('Свёртка class_exists() по известному набору классов')]
class ClassExistsFoldVisitor extends BaseVisitor
{
    private const array CHECKS = [
        'class_exists'     => [Node\Stmt\Class_::class, Node\Stmt\Enum_::class],
        'interface_exists' => [Node\Stmt\Interface_::class],
        'trait_exists'     => [Node\Stmt\Trait_::class],
        'enum_exists'      => [Node\Stmt\Enum_::class],
    ];

    private const array CORE_EXTENSIONS = ['Core', 'standard', 'SPL', 'Reflection', 'date', 'json', 'random', 'pcre'];

    /** @var array<string, class-string<Node\Stmt\ClassLike>> lowercase имя → вид объявления */
    private array $declared = [];

    /** @var array<string, true> имена, объявленные не на верхнем уровне (зависят от условий) */
    private array $conditional = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->declared    = [];
        $this->conditional = [];

        $topLevel = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassLike && $stmt->name instanceof Node\Identifier) {
                    $this->declared[strtolower($stmt->name->toString())] = $stmt::class;
                    $topLevel[spl_object_id($stmt)]                       = true;
                }
            }
        }

        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier && !isset($topLevel[spl_object_id($class)])) {
                $this->conditional[strtolower($class->name->toString())] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\New_)
            && $node->class instanceof Node\Scalar\String_
            && isset($this->declared[strtolower(ltrim($node->class->value, '\\'))])
        ) {
            $node->class = new Node\Name(ltrim($node->class->value, '\\'));

            return $node;
        }

        if (
            !$node instanceof Node\Expr\FuncCall
            || $node->getAttribute('runtimeCheck') === true
            || !$node->name instanceof Node\Name
            || !isset(self::CHECKS[strtolower($node->name->toString())])
            || !isset($node->args[0])
            || !$node->args[0] instanceof Node\Arg
            || !$node->args[0]->value instanceof Node\Scalar\String_
        ) {
            return null;
        }

        $exists = $this->exists(ltrim($node->args[0]->value->value, '\\'), self::CHECKS[strtolower($node->name->toString())]);

        return $exists === null ? null : new Node\Expr\ConstFetch(new Node\Name($exists ? 'true' : 'false'));
    }

    /**
     * @param list<class-string<Node\Stmt\ClassLike>> $kinds
     */
    private function exists(string $name, array $kinds): ?bool
    {
        $lower = strtolower($name);
        if (isset($this->conditional[$lower])) {
            return null;
        }

        if (isset($this->declared[$lower])) {
            return in_array($this->declared[$lower], $kinds, true);
        }

        if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)) {
            $reflection = new \ReflectionClass($name);
            if (!$reflection->isInternal() || !in_array($reflection->getExtensionName(), self::CORE_EXTENSIONS, true)) {
                return null;
            }

            return match (true) {
                $reflection->isEnum()      => in_array(Node\Stmt\Enum_::class, $kinds, true),
                $reflection->isInterface() => in_array(Node\Stmt\Interface_::class, $kinds, true),
                $reflection->isTrait()     => in_array(Node\Stmt\Trait_::class, $kinds, true),
                default                    => in_array(Node\Stmt\Class_::class, $kinds, true),
            };
        }

        return str_contains($name, '\\') ? false : null;
    }
}
