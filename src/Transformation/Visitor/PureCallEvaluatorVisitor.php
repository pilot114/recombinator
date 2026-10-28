<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Analysis\PurityAnalyzer;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\SandboxError;
use Recombinator\Support\SandboxRunner;

/**
 * Выполняет в песочнице вызовы чистого кода программы с константными аргументами и
 * подставляет результат литералом:
 *
 *   Inflector::tableize('UserProfile')            → 'user_profile'
 *   (new Parser('/users/{id}'))->variables()      → ['id']
 *   routeName('GET', '/users')                    → 'get_users'
 *
 * Чистота — по {@see PurityAnalyzer} (транзитивно, по графу вызовов). В песочницу
 * попадают только объявления, от которых зависит вызов: классы и функции его графа
 * вызовов, их предки, интерфейсы, трейты и всё, что они называют по имени. Результат
 * подставляется, если это значение без объектов (скаляры, массивы) разумного размера;
 * исключение, таймаут, объект в результате — вызов остаётся.
 */
#[VisitorMeta('Выполнение чистых функций и методов с константными аргументами в песочнице')]
class PureCallEvaluatorVisitor extends BaseVisitor
{
    private const int TIMEOUT = 3;

    private const string MEMORY = '256M';

    /** Результат длиннее (в сериализованном виде) не подставляется: код разрастётся. */
    private const int MAX_RESULT = 4096;

    private ?PurityAnalyzer $purity = null;

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    /** @var array<string, Node\Stmt\Function_> */
    private array $functions = [];

    /** @var list<Node\Stmt\Const_> */
    private array $constants = [];

    /** @var array<int, int> объявление → порядковый номер в программе */
    private array $order = [];

    /** @var array<int, string> объявление → напечатанный код */
    private array $printed = [];

    private readonly StandardPrinter $printer;

    private readonly ExecutionCache $cache;

    public function __construct(?ExecutionCache $cache = null)
    {
        $this->printer = new StandardPrinter();
        $this->cache   = $cache ?? new ExecutionCache();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->purity    = null;
        $this->classes   = [];
        $this->functions = [];
        $this->constants = [];
        $this->printed   = [];
        $this->order     = [];
        if ($this->hasPendingIncludes()) {
            return null;
        }

        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                $this->order[spl_object_id($stmt)] = count($this->order);
                if ($stmt instanceof Node\Stmt\ClassLike && $stmt->name instanceof Node\Identifier) {
                    $this->classes[$stmt->name->toLowerString()] = $stmt;
                } elseif ($stmt instanceof Node\Stmt\Function_) {
                    $this->functions[$stmt->name->toLowerString()] = $stmt;
                } elseif (
                    $stmt instanceof Node\Stmt\Const_ && !new NodeFinder()->findFirst($stmt, static fn(Node $n): bool => $n instanceof Node\Expr\ClassConstFetch
                    || $n instanceof Node\Expr\New_ || $n instanceof Node\Expr\CallLike) instanceof \PhpParser\Node
                ) {
                    $this->constants[] = $stmt;
                }
            }
        }

        $this->purity = new PurityAnalyzer($this->classes, $nodes);

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$this->purity instanceof PurityAnalyzer || !$node instanceof Node\Expr\CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        $key = $this->target($node);
        if ($key === null || !$this->constantArgs($node)) {
            return null;
        }

        $value = $this->evaluate($node, $key);

        return $value === null ? null : $this->valueToNode($value[0]);
    }

    /** Ключ чистой функции или метода, которые выполнит вызов; null — не подходит. */
    private function target(Node\Expr\CallLike $call): ?string
    {
        assert($this->purity instanceof PurityAnalyzer);
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $key = 'fn:' . strtolower(ltrim($call->name->toString(), '\\'));

            return isset($this->functions[substr($key, 3)]) && $this->purity->isPure($key) ? $key : null;
        }

        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            $class = $call->class->toLowerString();
            if (in_array($class, ['self', 'static', 'parent'], true)) {
                return null;
            }

            $key    = $this->purity->methodKey($class, $call->name->toString());
            $method = $key === null ? null : $this->method($key);

            return $method instanceof Node\Stmt\ClassMethod && $method->isStatic() && $method->isPublic() && $this->purity->isPure((string) $key) ? $key : null;
        }

        // (new C(…))->m(…): объект создаётся тут же — состояние известно
        if ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier && $call->var instanceof Node\Expr\New_) {
            $new = $call->var;
            if (!$new->class instanceof Node\Name || !$this->purity->isPureConstruction($new->class->toString()) || !$this->constantArgs($new)) {
                return null;
            }

            $key    = $this->purity->methodKey($new->class->toString(), $call->name->toString());
            $method = $key === null ? null : $this->method($key);

            return $method instanceof Node\Stmt\ClassMethod && !$method->isStatic() && $method->isPublic() && $this->purity->isPure((string) $key) ? $key : null;
        }

        return null;
    }

    private function method(string $key): ?Node\Stmt\ClassMethod
    {
        [$class, $method] = explode('::', $key, 2);

        return ($this->classes[$class] ?? null)?->getMethod($method);
    }

    private function constantArgs(Node\Expr\CallLike $call): bool
    {
        return array_all($call->getRawArgs(), fn(Node $arg): bool => $arg instanceof Node\Arg && !$arg->unpack && !$arg->byRef && $this->constant($arg->value));
    }

    private function constant(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar\InterpolatedString => false,
            $expr instanceof Node\Scalar\MagicConst => false,
            $expr instanceof Node\Scalar => true,
            $expr instanceof Node\Expr\ConstFetch => true,
            $expr instanceof Node\Expr\ClassConstFetch => $expr->class instanceof Node\Name && !in_array($expr->class->toLowerString(), ['self', 'static', 'parent'], true),
            $expr instanceof Node\Expr\UnaryMinus, $expr instanceof Node\Expr\UnaryPlus => $this->constant($expr->expr),
            $expr instanceof Node\Expr\BinaryOp => $this->constant($expr->left) && $this->constant($expr->right),
            $expr instanceof Node\Expr\Array_ => array_all($expr->items, fn(Node\ArrayItem $item): bool => !$item->byRef && !$item->unpack
                && $this->constant($item->value) && (!$item->key instanceof Node\Expr || $this->constant($item->key))),
            default => false,
        };
    }

    /**
     * Значение вызова (в массиве из одного элемента — null тоже значение) или null.
     *
     * @return array{mixed}|null
     */
    private function evaluate(Node\Expr\CallLike $call, string $key): ?array
    {
        $code = "<?php\n" . $this->preamble($call, $key) . "\n"
            . '$__v = ' . $this->printer->prettyPrintExpr($this->detached($call)) . ";\n"
            . '$__plain = static function ($v) use (&$__plain): bool { return is_array($v) ? array_all($v, $__plain) '
            . ': !is_object($v) && !is_resource($v) && (!is_float($v) || is_finite($v)); };' . "\n"
            . 'echo $__plain($__v) ? serialize($__v) : "";';

        $cacheKey = 'pure-call:' . sha1($code);
        if ($this->cache->has($cacheKey)) {
            $output = $this->cache->get($cacheKey);
        } else {
            $output = SandboxRunner::shared()->run($code, self::TIMEOUT, self::MEMORY);
            if ($output instanceof SandboxError && $output->isTimeout()) {
                return null; // таймаут зависит от нагрузки — не запоминаем
            }

            $output = $output instanceof SandboxError ? '' : $output;
            $this->cache->set($cacheKey, $output);
        }

        if (!is_string($output) || $output === '' || strlen($output) > self::MAX_RESULT) {
            return null;
        }

        $value = @unserialize($output, ['allowed_classes' => false]);

        return $value === false && $output !== serialize(false) ? null : [$value];
    }

    /** Копия вызова без атрибутов исходного дерева (для печати). */
    private function detached(Node\Expr $expr): Node\Expr
    {
        $copy = $this->deepClone($expr);
        assert($copy instanceof Node\Expr);

        return $copy;
    }

    /** Объявления, нужные вызову: граф вызовов и всё, что в нём названо по имени. */
    private function preamble(Node\Expr\CallLike $call, string $key): string
    {
        assert($this->purity instanceof PurityAnalyzer);
        $classes   = [];
        $functions = [];
        $queue     = [];
        foreach ($this->purity->closure($key) as $dependency) {
            if (str_starts_with($dependency, 'fn:')) {
                $queue[] = ['fn', substr($dependency, 3)];
            } elseif (str_contains($dependency, '::')) {
                $queue[] = ['class', explode('::', $dependency, 2)[0]];
            }
        }

        foreach ($this->referencedNames([$call]) as $kind => $names) {
            foreach (array_keys($names) as $name) {
                $queue[] = [$kind, $name];
            }
        }

        while ($queue !== []) {
            [$kind, $name] = array_pop($queue);
            if ($kind === 'class' && isset($this->classes[$name]) && !isset($classes[$name])) {
                $classes[$name] = $this->classes[$name];
                $node           = $this->classes[$name];
            } elseif ($kind === 'fn' && isset($this->functions[$name]) && !isset($functions[$name])) {
                $functions[$name] = $this->functions[$name];
                $node             = $this->functions[$name];
            } else {
                continue;
            }

            foreach ($this->referencedNames([$node]) as $refKind => $names) {
                foreach (array_keys($names) as $ref) {
                    $queue[] = [$refKind, $ref];
                }
            }
        }

        // В порядке исходной программы: родитель объявлен раньше наследника
        $parts = [];
        foreach ([...$this->constants, ...array_values($classes), ...array_values($functions)] as $node) {
            $parts[$this->order[spl_object_id($node)] ?? 0] = $this->print($node);
        }

        ksort($parts);

        return implode("\n", $parts);
    }

    /**
     * @param  array<Node> $nodes
     * @return array{class: array<string, true>, fn: array<string, true>}
     */
    private function referencedNames(array $nodes): array
    {
        $result = ['class' => [], 'fn' => []];
        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => $n instanceof Node\Name || $n instanceof Node\Scalar\String_) as $node) {
            if ($node instanceof Node\Scalar\String_) {
                $name = strtolower(ltrim(explode('::', $node->value, 2)[0], '\\'));
                if (isset($this->classes[$name])) {
                    $result['class'][$name] = true;
                } elseif (isset($this->functions[$name])) {
                    $result['fn'][$name] = true;
                }

                continue;
            }

            assert($node instanceof Node\Name);
            $name   = strtolower(ltrim($node->toString(), '\\'));
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node\Expr\FuncCall && $parent->name === $node) {
                $result['fn'][$name] = true;
            } else {
                $result['class'][$name] = true;
            }
        }

        return $result;
    }

    private function print(Node $node): string
    {
        return $this->printed[spl_object_id($node)] ??= $this->printer->prettyPrint([$this->detachedStmt($node)]);
    }

    private function detachedStmt(Node $node): Node
    {
        return $this->deepClone($node);
    }

    private function valueToNode(mixed $value): Node\Expr
    {
        return match (true) {
            $value === null => new Node\Expr\ConstFetch(new Node\Name('null')),
            is_bool($value) => new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false')),
            is_int($value) => new Node\Scalar\Int_($value),
            is_float($value) => new Node\Scalar\Float_($value),
            is_string($value) => new Node\Scalar\String_($value),
            is_array($value) => new Node\Expr\Array_(array_map(
                fn(int|string $k): Node\ArrayItem => new Node\ArrayItem($this->valueToNode($value[$k]), is_int($k) ? new Node\Scalar\Int_($k) : new Node\Scalar\String_($k)),
                array_keys($value),
            )),
            default => new Node\Expr\ConstFetch(new Node\Name('null')),
        };
    }
}
