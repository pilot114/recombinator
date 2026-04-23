<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Analysis\SideEffectClassifier;
use Recombinator\Domain\SideEffectType;

/**
 * Устранение повторных вызовов чистых функций/методов с одинаковыми аргументами.
 *
 * Сценарий A — первый вызов уже присвоен переменной:
 *   $s = hsum($a, $b);
 *   $t = hsum($a, $b) + 1;
 * →
 *   $s = hsum($a, $b);
 *   $t = $s + 1;
 *
 * Сценарий Б — первый вызов нигде не присвоен (вводится новая переменная):
 *   if (hsum($a, $b) > 0) { echo hsum($a, $b); }
 * →
 *   $__cse0 = hsum($a, $b);
 *   if ($__cse0 > 0) { echo $__cse0; }
 *
 * «Чистой» считается функция из встроенного списка SideEffectClassifier
 * или пользовательская функция, определённая в текущем файле.
 */
#[VisitorMeta('pure f(x) вызывается дважды → $v = f(x); переиспользование $v')]
class PureFunctionCacheVisitor extends BaseVisitor
{
    private readonly SideEffectClassifier $classifier;
    private readonly StandardPrinter $printer;

    /** @var array<string, true> имена функций, определённых в текущем файле */
    private array $localFunctions = [];

    /** @var array<int, string> spl_object_id(call) → varName замены */
    private array $replacements = [];

    /** @var list<array{before: Node\Stmt, varName: string, call: Node\Expr\FuncCall, parentStmts: Node}> */
    private array $toHoist = [];

    public function __construct()
    {
        $this->classifier = new SideEffectClassifier();
        $this->printer    = new StandardPrinter();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->localFunctions = [];
        $this->replacements   = [];
        $this->toHoist        = [];

        // Собираем пользовательские функции из файла (считаются чистыми по умолчанию)
        foreach ($this->findNode(Node\Stmt\Function_::class) as $fn) {
            if (is_string($fn->name->name)) {
                $this->localFunctions[$fn->name->name] = true;
            }
        }

        // Анализируем каждое тело функции/метода отдельно
        $bodies = array_merge(
            $this->findNode(Node\Stmt\Function_::class),
            $this->findNode(Node\Stmt\ClassMethod::class),
        );

        $cseCounter = 0;
        foreach ($bodies as $body) {
            /** @var Node\Stmt\Function_|Node\Stmt\ClassMethod $body */
            if ($body->stmts === null) {
                continue;
            }

            $this->processBody($body->stmts, $body, $cseCounter);
        }

        return null;
    }

    /**
     * Анализирует тело функции: ищет дублирующиеся вызовы чистых функций.
     *
     * @param array<Node\Stmt>                                    $stmts
     * @param Node\Stmt\Function_|Node\Stmt\ClassMethod           $bodyNode
     */
    private function processBody(array $stmts, Node $bodyNode, int &$cseCounter): void
    {
        /** @var array<string, array{varName: string, firstId: int, hoistBefore: Node\Stmt|null}> */
        $seen = [];

        $finder = new NodeFinder();

        foreach ($stmts as $stmt) {
            /** @var list<Node\Expr\FuncCall> */
            $calls = $finder->findInstanceOf([$stmt], Node\Expr\FuncCall::class);

            foreach ($calls as $call) {
                if (!$call->name instanceof Node\Name) {
                    continue;
                }

                $funcName = $call->name->toString();
                if (!$this->isPure($funcName, $call)) {
                    continue;
                }

                $key = $this->printer->prettyPrintExpr($call);

                if (!isset($seen[$key])) {
                    $assignedVar = $this->extractAssignedVar($call);

                    if ($assignedVar !== null) {
                        // Сценарий А: результат уже присваивается переменной — запоминаем её
                        $seen[$key] = [
                            'varName'     => $assignedVar,
                            'firstId'     => spl_object_id($call),
                            'hoistBefore' => null,
                        ];
                    } else {
                        // Сценарий Б: нужен новый __cse, хойстим перед текущим стейтментом
                        $varName = '__cse' . $cseCounter++;
                        $seen[$key] = [
                            'varName'     => $varName,
                            'firstId'     => spl_object_id($call),
                            'hoistBefore' => $stmt,
                        ];
                        // Первый вызов тоже заменяем на переменную
                        $this->replacements[spl_object_id($call)] = $varName;
                        $this->toHoist[] = [
                            'before'     => $stmt,
                            'varName'    => $varName,
                            'call'       => $call,
                            'parentNode' => $bodyNode,
                        ];
                    }

                    continue;
                }

                // Повторный вызов — заменяем на переменную (не первый экземпляр)
                if (spl_object_id($call) !== $seen[$key]['firstId']) {
                    $this->replacements[spl_object_id($call)] = $seen[$key]['varName'];
                }
            }
        }
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // Заменяем помеченные вызовы переменными
        if ($node instanceof Node\Expr\FuncCall) {
            $id      = spl_object_id($node);
            $varName = $this->replacements[$id] ?? null;
            if ($varName !== null) {
                return new Node\Expr\Variable($varName);
            }
        }

        // Вставляем hoisted присваивания в тела функций
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $inserted = $this->buildHoisted($node);
            if ($inserted !== null) {
                $nodeNew        = clone $node;
                $nodeNew->stmts = $inserted;
                return $nodeNew;
            }
        }

        return parent::leaveNode($node);
    }

    /**
     * @return array<Node\Stmt>|null
     */
    private function buildHoisted(Node $bodyNode): ?array
    {
        $relevant = array_filter($this->toHoist, fn ($h) => $h['parentNode'] === $bodyNode);
        if ($relevant === []) {
            return null;
        }

        /** @var array<int, list<array{varName: string, call: Node\Expr\FuncCall}>> stmtId → inserts */
        $insertBefore = [];
        foreach ($relevant as $h) {
            $insertBefore[spl_object_id($h['before'])][] = [
                'varName' => $h['varName'],
                'call'    => $h['call'],
            ];
        }

        $result = [];
        foreach ($bodyNode->stmts as $stmt) {
            $stmtId = spl_object_id($stmt);
            if (isset($insertBefore[$stmtId])) {
                foreach ($insertBefore[$stmtId] as $ins) {
                    $result[] = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable($ins['varName']),
                            $this->deepClone($ins['call'])
                        )
                    );
                }
            }

            $result[] = $stmt;
        }

        $this->toHoist = array_values(
            array_filter($this->toHoist, fn ($h) => $h['parentNode'] !== $bodyNode)
        );

        return $result;
    }

    private function isPure(string $funcName, Node\Expr\FuncCall $call): bool
    {
        if ($this->classifier->classify($call) === SideEffectType::PURE) {
            return true;
        }

        return isset($this->localFunctions[$funcName]);
    }

    /**
     * Если вызов является прямым RHS присваивания $var = call(...), возвращает имя $var.
     */
    private function extractAssignedVar(Node\Expr\FuncCall $call): ?string
    {
        $parent = $call->getAttribute('parent');
        if (!$parent instanceof Node\Expr\Assign || $parent->expr !== $call) {
            return null;
        }

        $lhs = $parent->var;
        return $lhs instanceof Node\Expr\Variable && is_string($lhs->name) ? $lhs->name : null;
    }
}
