<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Переименование переменной во всей её области видимости: функции (метода, замыкания)
 * или файла. Стрелочные функции видят переменные родителя, замыкания — через `use`.
 * Переименование, которое могло бы изменить поведение, отклоняется исключением.
 */
final class VariableRenamer extends NodeVisitorAbstract
{
    private const array RESERVED = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'argv', 'argc'];

    /** Обращаются к переменным по имени-строке: после переименования они бы её не нашли. */
    private const array BY_NAME = ['compact', 'extract', 'get_defined_vars', 'parse_str'];

    /** @var list<array{Expr\Variable, int}> переменная и её область: 0 — файл, иначе spl_object_id функции */
    private array $variables = [];

    /** @var array<int, int> spl_object_id переменной → область */
    private array $scopeOf = [];

    /** @var array<int, array<string, list<int>>> область → имя → замыкания с `use` этого имени */
    private array $uses = [];

    /** @var array<int, string> область → конструкция, обращающаяся к переменным по имени */
    private array $byName = [];

    /** @var array<string, true> имена из `global` */
    private array $globals = [];

    private bool $usesGlobalsArray = false;

    /** @var list<int> */
    private array $stack = [0];

    private function __construct()
    {
    }

    /**
     * @param  array<Node> $ast
     * @throws \InvalidArgumentException переименование небезопасно
     */
    public static function rename(array $ast, Expr\Variable $target, string $to): void
    {
        $from = $target->name;
        if (!is_string($from) || in_array($from, self::RESERVED, true)) {
            throw new \InvalidArgumentException('This variable cannot be renamed');
        }

        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $to) !== 1 || in_array($to, self::RESERVED, true)) {
            throw new \InvalidArgumentException('Invalid variable name: $' . $to);
        }

        if ($from === $to) {
            return;
        }

        $self = new self();
        new NodeTraverser($self)->traverse($ast);

        $scope = $self->scopeOf[spl_object_id($target)] ?? null;
        if ($scope === null) {
            throw new \InvalidArgumentException('Variable is not part of the code');
        }

        // Замыкания с `use ($from)` делят переменную с областью, где они созданы
        $affected = [$scope => true];
        for ($queue = [$scope]; $queue !== [];) {
            foreach ($self->uses[array_pop($queue)][$from] ?? [] as $closure) {
                if (!isset($affected[$closure])) {
                    $affected[$closure] = true;
                    $queue[]            = $closure;
                }
            }
        }

        $variables = array_filter($self->variables, static fn(array $v): bool => isset($affected[$v[1]]));
        foreach ($variables as [$variable]) {
            if (!is_string($variable->name)) {
                throw new \InvalidArgumentException('Scope uses variable variables');
            }

            if ($variable->name === $to) {
                throw new \InvalidArgumentException('Name $' . $to . ' is already used here');
            }
        }

        foreach (array_keys($affected) as $id) {
            if (isset($self->byName[$id])) {
                throw new \InvalidArgumentException('Scope accesses variables by name (' . $self->byName[$id] . ')');
            }
        }

        // Переменные файла видны через `global` и `$GLOBALS` из любой функции
        if (isset($affected[0]) && (isset($self->globals[$from]) || $self->usesGlobalsArray)) {
            throw new \InvalidArgumentException('$' . $from . ' is used as a global variable');
        }

        foreach ($variables as [$variable]) {
            if ($variable->name === $from) {
                $variable->name = $to;
            }
        }
    }

    public function enterNode(Node $node): null
    {
        $scope = $this->stack[array_key_last($this->stack)];
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Expr\Closure) {
            $inner = spl_object_id($node);
            foreach ($node instanceof Expr\Closure ? $node->uses : [] as $use) {
                // Переменная в `use` принадлежит внешней области и связывает её с замыканием
                $this->add($use->var, $scope);
                if (is_string($use->var->name)) {
                    $this->uses[$scope][$use->var->name][] = $inner;
                }
            }

            $this->stack[] = $inner;

            return null;
        }

        // Переменные `use` уже учтены при входе в замыкание
        if ($node instanceof Expr\Variable && !isset($this->scopeOf[spl_object_id($node)])) {
            $this->add($node, $scope);
            $this->usesGlobalsArray = $this->usesGlobalsArray || $node->name === 'GLOBALS';
        }

        if ($node instanceof Node\Stmt\Global_) {
            foreach ($node->vars as $var) {
                if ($var instanceof Expr\Variable && is_string($var->name)) {
                    $this->globals[$var->name] = true;
                }
            }
        }

        if ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_) {
            $this->byName[$scope] = $node instanceof Expr\Eval_ ? 'eval' : 'include';
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::BY_NAME, true)) {
            $this->byName[$scope] = $node->name->toLowerString();
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Expr\Closure) {
            array_pop($this->stack);
        }

        return null;
    }

    private function add(Expr\Variable $variable, int $scope): void
    {
        $this->variables[]                       = [$variable, $scope];
        $this->scopeOf[spl_object_id($variable)] = $scope;
    }
}
