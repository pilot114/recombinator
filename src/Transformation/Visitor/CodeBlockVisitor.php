<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Comment;
use PhpParser\Node;

/**
 * Разбивает верхнеуровневый код на именованные смысловые блоки.
 *
 * Классифицирует каждое утверждение по типу операции, группирует подряд
 * идущие утверждения одного типа под одним заголовком и разделяет соседние
 * группы пустой строкой.
 *
 * Формат вывода:
 *
 *   # Чтение глобального состояния
 *   $tmp1 = $_GET['username'] ?? 'guest';
 *   $tmp2 = $_GET['pass'] ?? '';
 *
 *   # Вычисления
 *   $ok = $tmp1 . '_' . $tmp2 === 'admin_secret';
 *
 *   # Вывод
 *   echo "result: {$ok}\n";
 */
#[VisitorMeta('Разбивка верхнеуровневого кода на смысловые блоки')]
class CodeBlockVisitor extends BaseVisitor
{
    private const array SUPERGLOBALS = [
        '_GET', '_POST', '_SERVER', '_SESSION',
        '_COOKIE', '_REQUEST', '_ENV', '_FILES', 'GLOBALS',
    ];

    /** Block names, keyed by classification result. */
    private const array LABELS = [
        'input'   => 'Чтение глобального состояния',
        'output'  => 'Вывод',
        'return'  => 'Возврат значения',
        'control' => 'Управление потоком',
        'compute' => 'Вычисления',
        'other'   => 'Прочее',
    ];

    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        return $this->groupStatements($nodes);
    }

    // ── Core logic ────────────────────────────────────────────────────────────

    /**
     * Returns a new statement list where each type-change boundary is marked
     * with a `# Label` comment on the first statement and a blank-line Nop
     * before it (except for the very first group).
     *
     * @param  array<Node> $nodes
     * @return array<Node>
     */
    private function groupStatements(array $nodes): array
    {
        $result   = [];
        $prevType = null;

        foreach ($nodes as $node) {
            $type = $this->classifyStatement($node);

            if ($type !== $prevType) {
                if ($prevType !== null) {
                    $result[] = new Node\Stmt\Nop(); // blank line between groups
                }

                $this->prependBlockComment($node, self::LABELS[$type]);
                $prevType = $type;
            }

            $result[] = $node;
        }

        return $result;
    }

    // ── Classification ────────────────────────────────────────────────────────

    /** @return key-of<self::LABELS> */
    private function classifyStatement(Node $node): string
    {
        if ($node instanceof Node\Stmt\Echo_) {
            return 'output';
        }

        if ($node instanceof Node\Stmt\Return_) {
            return 'return';
        }

        if (
            $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
        ) {
            return 'control';
        }

        if ($node instanceof Node\Stmt\Expression) {
            if ($this->containsSuperglobal($node)) {
                return 'input';
            }

            return 'compute';
        }

        return 'other';
    }

    private function containsSuperglobal(Node $node): bool
    {
        return array_any($this->findNode(Node\Expr\Variable::class, $node), fn($var): bool => is_string($var->name) && in_array($var->name, self::SUPERGLOBALS, true));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function prependBlockComment(Node $node, string $label): void
    {
        $existing = $node->getComments();
        $node->setAttribute('comments', array_merge([new Comment('# ' . $label)], $existing));
    }
}
