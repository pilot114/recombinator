<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Support\Sandbox;
use Recombinator\Support\SandboxError;
use Recombinator\Support\SandboxRunner;

/**
 * Выполняет программу целиком, если она детерминирована и единственный её
 * внешний эффект — вывод, и заменяет её этим выводом:
 *
 *   class Counter { ... }  $c = new Counter(0); ...  echo "Split: $low/$high";
 *   →
 *   echo 'Split: 7/16 ...';
 *
 * Это та же «предвыполнение чистого кода», что у PreExecutionVisitor, но для всей
 * программы: статическое состояние (реестры, логи), исключения, циклы с
 * переприсваиванием объектов вычисляет сам PHP, а не цепочка правил.
 *
 * Программа детерминирована, если в ней (включая тела всех функций и методов) нет:
 * - вызовов встроенных функций вне списка чистых (время, случайность, файлы, сеть…);
 * - встроенных классов, кроме исключений и контейнеров SPL;
 * - строк с именами иных встроенных функций и классов (`$f = 'system'; $f()`);
 * - суперглобальных переменных, include/eval/exit/``, __DIR__/__FILE__/__LINE__;
 * - констант окружения (PHP_VERSION, PHP_OS…).
 *
 * Предупреждение или исключение при выполнении — отказ: вывод не равен поведению программы.
 */
#[VisitorMeta('Выполнение детерминированной программы: весь код → её вывод', targets: [Node\Stmt::class])]
class ProgramEvaluatorVisitor extends BaseVisitor
{
    private const int TIMEOUT = 5;

    private const string MEMORY = '128M';

    /** Детерминированные встроенные функции сверх списка Sandbox: вывод, интроспекция, массивы */
    private const array EXTRA_FUNCTIONS = [
        'printf', 'vprintf', 'print_r', 'var_dump', 'var_export',
        'get_class', 'get_called_class', 'get_object_vars', 'get_parent_class', 'property_exists',
        'method_exists', 'class_exists', 'interface_exists', 'enum_exists', 'trait_exists', 'is_a',
        'is_subclass_of', 'func_get_args', 'func_num_args', 'iterator_to_array', 'iterator_count',
        'array_key_first', 'array_key_last', 'array_is_list', 'array_walk', 'array_push', 'array_pop',
        'array_shift', 'array_unshift', 'array_splice', 'array_any', 'array_all', 'array_find',
        'spl_object_id', 'spl_object_hash', 'define', 'defined', 'constant', 'settype', 'mb_str_pad',
    ];

    private const array SAFE_CLASSES = [
        'stdclass', 'arrayobject', 'arrayiterator', 'splstack', 'splqueue', 'splobjectstorage',
        'splfixedarray', 'spldoublylinkedlist', 'splpriorityqueue', 'splminheap', 'splmaxheap',
        'closure', 'weakmap', 'generator',
    ];

    private const array SUPERGLOBALS = [
        '_GET', '_POST', '_SERVER', '_ENV', '_COOKIE', '_FILES', '_REQUEST', '_SESSION',
        'argv', 'argc', 'http_response_header',
    ];

    private const array CONSTANT_PREFIXES = [
        'PHP_EOL', 'PHP_INT_', 'PHP_FLOAT_', 'M_', 'E_', 'SORT_', 'JSON_', 'COUNT_', 'ARRAY_FILTER_',
        'STR_PAD_', 'PREG_', 'ENT_', 'MB_CASE_', 'PHP_ROUND_', 'NAN', 'INF',
    ];

    private readonly SandboxRunner $runner;

    public function __construct(?SandboxRunner $runner = null)
    {
        $this->runner = $runner ?? SandboxRunner::shared();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        if ($nodes === [] || $this->isAlreadyOutput($nodes) || !$this->isDeterministic($nodes)) {
            return null;
        }

        $output = $this->run($nodes);
        if ($output === null) {
            return null;
        }

        return $output === '' ? [] : [new Node\Stmt\Echo_([new Node\Scalar\String_($output)])];
    }

    public function enterNode(Node $node): int
    {
        return NodeVisitor::STOP_TRAVERSAL;
    }

    /**
     * Программа уже свелась к выводу литералов — выполнять нечего.
     *
     * @param array<Node> $nodes
     */
    private function isAlreadyOutput(array $nodes): bool
    {
        return array_all(
            $nodes,
            static fn(Node $node): bool => $node instanceof Node\Stmt\Nop
                || $node instanceof Node\Stmt\InlineHTML
                || $node instanceof Node\Stmt\Declare_
                || $node instanceof Node\Stmt\Echo_ && array_all(
                    $node->exprs,
                    static fn(Node\Expr $expr): bool => $expr instanceof Node\Scalar\String_
                        || $expr instanceof Node\Scalar\Int_
                        || $expr instanceof Node\Scalar\Float_,
                ),
        );
    }

    /** @param array<Node> $nodes */
    private function isDeterministic(array $nodes): bool
    {
        $userClasses   = [];
        $userFunctions = [];
        $userConsts    = [];
        foreach ($this->findNode(Node::class, $nodes) as $node) {
            match (true) {
                $node instanceof Node\Stmt\ClassLike && $node->name instanceof Node\Identifier
                    => $userClasses[strtolower($node->name->toString())] = true,
                $node instanceof Node\Stmt\Function_ => $userFunctions[strtolower($node->name->toString())] = true,
                $node instanceof Node\Const_ => $userConsts[$node->name->toString()] = true,
                default => null,
            };
        }

        $checker = new class ($this, $userClasses, $userFunctions, $userConsts) extends NodeVisitorAbstract {
            public bool $ok = true;

            /**
             * @param array<string, true> $classes
             * @param array<string, true> $functions
             * @param array<string, true> $consts
             */
            public function __construct(
                private readonly ProgramEvaluatorVisitor $owner,
                private readonly array $classes,
                private readonly array $functions,
                private readonly array $consts,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if (!$this->owner->allows($node, $this->classes, $this->functions, $this->consts)) {
                    $this->ok = false;
                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        new NodeTraverser($checker)->traverse($nodes);

        return $checker->ok;
    }

    /**
     * @param array<string, true> $classes
     * @param array<string, true> $functions
     * @param array<string, true> $consts
     */
    public function allows(Node $node, array $classes, array $functions, array $consts): bool
    {
        if (
            $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\ShellExec
            || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Stmt\HaltCompiler
            || $node instanceof Node\Scalar\MagicConst\Dir || $node instanceof Node\Scalar\MagicConst\File
            || $node instanceof Node\Scalar\MagicConst\Line || $node instanceof Node\Stmt\Namespace_
        ) {
            return false;
        }

        if ($node instanceof Node\Expr\Variable) {
            return !is_string($node->name) || !in_array($node->name, self::SUPERGLOBALS, true);
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            return $this->isAllowedFunction($node->name->toString(), $functions);
        }

        if (
            ($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\StaticPropertyFetch)
            && $node->class instanceof Node\Name
        ) {
            return $this->isAllowedClass($node->class->toString(), $classes);
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();

            return in_array(strtolower($name), ['true', 'false', 'null'], true)
                || isset($consts[$name])
                || array_any(self::CONSTANT_PREFIXES, static fn(string $prefix): bool => str_starts_with($name, $prefix));
        }

        // Строка с именем встроенной функции или класса может стать вызовом: $f = 'system'; $f()
        if ($node instanceof Node\Scalar\String_) {
            $name = ltrim($node->value, '\\');
            if (preg_match('/^[A-Za-z_][\w\\\\]*$/', $name) !== 1) {
                return true;
            }

            if (function_exists($name) && !$this->isAllowedFunction($name, $functions)) {
                return false;
            }

            return !class_exists($name, false) || $this->isAllowedClass($name, $classes);
        }

        return true;
    }

    /** @param array<string, true> $functions */
    private function isAllowedFunction(string $name, array $functions): bool
    {
        $lower = strtolower(ltrim($name, '\\'));

        return isset($functions[$lower]) || Sandbox::isPureFunction($lower) || in_array($lower, self::EXTRA_FUNCTIONS, true);
    }

    /** @param array<string, true> $classes */
    private function isAllowedClass(string $name, array $classes): bool
    {
        $lower = strtolower(ltrim($name, '\\'));
        if (isset($classes[$lower]) || in_array($lower, ['self', 'static', 'parent'], true) || in_array($lower, self::SAFE_CLASSES, true)) {
            return true;
        }

        // Встроенные исключения: new \RuntimeException(...)
        return class_exists($lower, false)
            && is_subclass_of($lower, \Throwable::class)
            && new \ReflectionClass($lower)->isInternal();
    }

    /**
     * Вывод программы или null, если она завершилась с ошибкой или предупреждением.
     *
     * @param array<Node> $nodes
     */
    private function run(array $nodes): ?string
    {
        $declares = [];
        $body     = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Declare_ && $node->stmts === null && $body === []) {
                $declares[] = $node;
            } else {
                $body[] = $node;
            }
        }

        // Любое предупреждение — отказ: иначе вывод не совпал бы с поведением программы
        $guard = new ParserFactory()->createForNewestSupportedVersion()->parse(
            '<?php set_error_handler(static function (int $no, string $message): never { throw new \ErrorException($message, 0, $no); });',
        ) ?? [];

        $code   = new StandardPrinter()->prettyPrintFile([...$declares, ...$guard, ...$body]);
        $result = $this->runner->run($code, self::TIMEOUT, self::MEMORY);

        return $result instanceof SandboxError ? null : $result;
    }
}
