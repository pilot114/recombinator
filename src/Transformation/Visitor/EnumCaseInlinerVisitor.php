<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Разворачивает backed-перечисления (enum с типом string/int):
 *
 * - `EnumName::Case`       → backing-значение (строковый/числовой литерал)
 * - `self::Case` внутри enum-метода → то же backing-значение
 * - `EnumName::staticMethod()`      → тело метода (если он состоит из одного return)
 * - Само определение enum удаляется после замены.
 *
 * Pure-enum (без backing type) не трогается — у него нет скалярного эквивалента.
 */
#[VisitorMeta('Разворачивание enum: EnumName::Case → backing-значение, удаление enum')]
class EnumCaseInlinerVisitor extends BaseVisitor
{
    /**
     * @var array<string, array{
     *   cases:   array<string, Node\Scalar\String_|Node\Scalar\LNumber>,
     *   methods: array<string, Node\Stmt\ClassMethod>
     * }>
     */
    private array $enums = [];

    /** Имя enum, тело которого сейчас обходится (для замены self::Case). */
    private ?string $currentEnum = null;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->enums       = [];
        $this->currentEnum = null;
        $this->collectEnums($nodes);
        return null;
    }

    /** @param array<Node> $nodes */
    private function collectEnums(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node\Stmt\Enum_) {
                continue;
            }

            if (!$node->name instanceof Node\Identifier) {
                continue;
            }

            if (!$node->scalarType instanceof \PhpParser\Node) {
                continue;
            }

            $cases   = [];
            $methods = [];

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name instanceof Node\Identifier && $stmt->expr instanceof \PhpParser\Node\Expr) {
                    $cases[$stmt->name->name] = $stmt->expr;
                }

                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name instanceof Node\Identifier) {
                    $methods[$stmt->name->name] = $stmt;
                }
            }

            $this->enums[$node->name->name] = ['cases' => $cases, 'methods' => $methods];
        }
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        // Фиксируем контекст обхода тела enum
        if ($node instanceof Node\Stmt\Enum_ && $node->name instanceof Node\Identifier) {
            if (isset($this->enums[$node->name->name])) {
                $this->currentEnum = $node->name->name;
            }

            return null;
        }

        // EnumName::CaseName → backing-значение
        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
        ) {
            $className = $node->class->toString();
            $constName = $node->name->name;

            // self::Case внутри тела enum
            if (strtolower($className) === 'self' && $this->currentEnum !== null) {
                $className = $this->currentEnum;
            }

            if (isset($this->enums[$className]['cases'][$constName])) {
                return $this->deepClone($this->enums[$className]['cases'][$constName]);
            }
        }

        // EnumName::staticMethod() — инлайн однострочного static-метода
        if (
            $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
        ) {
            $className  = $node->class->toString();
            $methodName = $node->name->name;

            if (isset($this->enums[$className]['methods'][$methodName])) {
                $method = $this->enums[$className]['methods'][$methodName];
                if (
                    $method->stmts !== null
                    && count($method->stmts) === 1
                    && $method->stmts[0] instanceof Node\Stmt\Return_
                    && $method->stmts[0]->expr instanceof Node\Expr
                ) {
                    return $this->deepClone($method->stmts[0]->expr);
                }
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Enum_ && $node->name instanceof Node\Identifier && isset($this->enums[$node->name->name])) {
            $this->currentEnum = null;
            $node->setAttribute('remove', true);
        }

        return parent::leaveNode($node);
    }
}
