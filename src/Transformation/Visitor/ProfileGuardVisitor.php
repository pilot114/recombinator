<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Support\TypeInference;

/**
 * Девиртуализация по профилю: вызов метода, цель которого тип получателя не определяет
 * (`$this->tip->handle($request)`), получает защищённую прямую ветку для класса,
 * который реально приходил туда на запросах из описания сервера (`profile`):
 *
 *   $this->tip->handle($request)
 *   →
 *   ($devirt7 = $this->tip) instanceof Slim_MiddlewareDispatcher_anonymous_1
 *       ? $devirt7->handle($request)      // тип известен: встраивание, удаление мёртвого кода
 *       : $devirt7->handle($request)      // прочие классы — как было
 *
 * Профиль: копия текущей программы, где получатель каждого такого вызова проходит через
 * счётчик классов, выполняется в каталоге входного файла на каждом запросе (результат
 * кэшируется по коду). Защищается вызов, где встретился ровно один класс программы.
 * Корректно при любом профиле: непредвиденный класс идёт по исходной ветке.
 */
#[VisitorMeta('Девиртуализация вызовов по профилю выполнения (защищённая ветка)')]
class ProfileGuardVisitor extends BaseVisitor
{
    /** @var array<int, string> номер места вызова → класс получателя по профилю */
    private array $plan = [];

    /** @var array<int, Node\Expr\MethodCall> */
    private array $sites = [];

    public function __construct(
        private readonly TargetEnvironment $environment,
        private readonly ?string $projectDir,
        private readonly ?string $entry,
        private readonly string $cacheDir,
    ) {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->plan  = [];
        $this->sites = [];
        if ($this->environment->profile === [] || $this->projectDir === null || $this->entry === null || $this->hasPendingIncludes()) {
            return null;
        }

        $classes = [];
        $methods = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
                foreach ($class->getMethods() as $method) {
                    $methods[$method->name->toLowerString()] = true;
                }
            }
        }

        $inference = new TypeInference($classes, $nodes);
        foreach ($this->findNode(Node\Expr\MethodCall::class) as $call) {
            if (
                $call->name instanceof Node\Identifier && !$call->isFirstClassCallable() && isset($methods[$call->name->toLowerString()])
                && $call->getAttribute('profileFallback') !== true && !$this->resolved($call, $inference)
                && !$this->insideConstantExpression($call) && $inference->enclosingFunction($call) instanceof Node\FunctionLike
            ) {
                $call->setAttribute('profileSite', count($this->sites));
                $this->sites[] = $call;
            }
        }

        if ($this->sites === []) {
            return null;
        }

        $observed = $this->profile($nodes);
        foreach ($observed as $id => $seen) {
            if (count($seen) !== 1 || !isset($this->sites[$id])) {
                continue;
            }

            $class = $classes[strtolower(array_key_first($seen))] ?? null;
            $name  = $this->sites[$id]->name;
            if (
                $class instanceof Node\Stmt\Class_ && !$class->isAbstract() && $name instanceof Node\Identifier
                && $inference->lookup($class, $name->toString()) instanceof Node\Stmt\ClassMethod
            ) {
                $this->plan[$id] = (string) $class->name;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        $id = $node instanceof Node\Expr\MethodCall ? $node->getAttribute('profileSite') : null;
        if (!is_int($id) || !isset($this->plan[$id]) || !$node instanceof Node\Expr\MethodCall) {
            return null;
        }

        $temp     = 'devirt' . $id . '_' . substr(sha1(spl_object_id($node) . microtime()), 0, 4);
        $direct   = new Node\Expr\MethodCall(new Node\Expr\Variable($temp), $node->name, $node->args);
        $fallback = new Node\Expr\MethodCall(new Node\Expr\Variable($temp), $node->name, array_map($this->deepClone(...), $node->args));
        $fallback->setAttribute('profileFallback', true);

        $guard = new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(new Node\Expr\Assign(new Node\Expr\Variable($temp), $node->var), new Node\Name\FullyQualified($this->plan[$id])),
            $direct,
            $fallback,
            $node->getAttributes(),
        );
        $guard->setAttribute('profileSite', null);
        $guard->setAttribute('profileGuard', true);
        $guard->setAttribute('profileFresh', true); // поставлена в этом проходе — снимать рано

        return $guard;
    }

    /**
     * Защита, которая за проход ничего не дала (прямая ветка осталась вызовом — тело
     * не встроилось), снимается: на месте снова исходный вызов, отмеченный, чтобы не
     * ставить защиту повторно.
     */
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (
            $node instanceof Node\Expr\Ternary && $node->getAttribute('profileFresh') !== true
            && $node->if instanceof Node\Expr\MethodCall && $node->if->var instanceof Node\Expr\Variable
            && $node->cond instanceof Node\Expr\Instanceof_ && $node->cond->expr instanceof Node\Expr\Assign
            && $node->cond->expr->var instanceof Node\Expr\Variable && is_string($node->cond->expr->var->name)
            && str_starts_with($node->cond->expr->var->name, 'devirt') && $node->if->var->name === $node->cond->expr->var->name
        ) {
            $call = new Node\Expr\MethodCall($node->cond->expr->expr, $node->if->name, $node->if->args, $node->getAttributes());
            $call->setAttribute('profileGuard', null);
            $call->setAttribute('profileFallback', true);

            return $call;
        }

        if ($node instanceof Node\Expr\Ternary && $node->getAttribute('profileFresh') === true) {
            $node->setAttribute('profileFresh', null);
        }

        return parent::leaveNode($node);
    }

    /**
     * Цель вызова уже определена типом получателя: у всех возможных классов — один метод.
     */
    private function resolved(Node\Expr\MethodCall $call, TypeInference $inference): bool
    {
        $types = $inference->typeOf($call->var);
        if ($types === null || $types === [] || !$call->name instanceof Node\Identifier) {
            return false;
        }

        $target = null;
        foreach ($types as $type) {
            foreach ($inference->subtypes($type) ?? [null] as $class) {
                if (!$class instanceof Node\Stmt\Class_) {
                    return false;
                }

                if ($class->isAbstract()) {
                    continue;
                }

                $method = $inference->lookup($class, $call->name->toString());
                if (!$method instanceof Node\Stmt\ClassMethod || $target instanceof Node\Stmt\ClassMethod && $target !== $method) {
                    return false;
                }

                $target = $method;
            }
        }

        return $target instanceof Node\Stmt\ClassMethod;
    }

    /** Значения по умолчанию свойств и констант — не выражения времени выполнения. */
    private function insideConstantExpression(Node $node): bool
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\PropertyItem || $parent instanceof Node\Const_ || $parent instanceof Node\Param || $parent instanceof Node\Stmt\StaticVar) {
                return true;
            }

            if ($parent instanceof Node\Stmt) {
                return false;
            }
        }

        return false;
    }

    /**
     * Классы получателей по местам вызова: выполнение инструментированной копии.
     *
     * @param  array<Node> $nodes
     * @return array<int, array<string, true>>
     */
    private function profile(array $nodes): array
    {
        $copy = [];
        foreach ($nodes as $node) {
            $copy[] = $this->deepClone($node);
        }

        $copy = new NodeTraverser(new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node): ?Node
            {
                $id = $node instanceof Node\Expr\MethodCall ? $node->getAttribute('profileSite') : null;
                if (is_int($id) && $node instanceof Node\Expr\MethodCall) {
                    $node->var = new Node\Expr\FuncCall(new Node\Name\FullyQualified('__rcb_site'), [
                        new Node\Arg(new Node\Scalar\Int_($id)),
                        new Node\Arg($node->var),
                    ]);
                }

                return null;
            }
        })->traverse($copy);

        $probe = new ParserFactory()->createForNewestSupportedVersion()->parse(<<<'PHP'
            <?php
            function __rcb_site(int $id, mixed $o): mixed
            {
                if (is_object($o)) {
                    $GLOBALS['__rcb_sites'][$id][get_class($o)] = true;
                }

                return $o;
            }
            register_shutdown_function(static function (): void {
                file_put_contents((string) getenv('RCB_PROFILE_OUT'), json_encode($GLOBALS['__rcb_sites'] ?? []));
            });
            PHP) ?? [];
        $code = new Standard()->prettyPrintFile([...$probe, ...$copy]);

        $key  = sha1($code . "\0" . json_encode($this->environment->profile));
        $file = $this->cacheDir . '/profile-' . $key . '.json';
        if (is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $this->normalized($cached);
            }
        }

        $dir    = dirname($this->projectDir . '/' . $this->entry);
        $script = $dir . '/__rcb_profile_' . getmypid() . '.php';
        $out    = tempnam(sys_get_temp_dir(), 'rcbprof');
        if ($out === false || file_put_contents($script, $code) === false) {
            return [];
        }

        $merged = [];
        try {
            foreach ($this->environment->profile as $request) {
                @unlink($out);
                $env = sprintf(
                    'REQUEST_METHOD=%s REQUEST_URI=%s RCB_PROFILE_OUT=%s',
                    escapeshellarg($request['method'] ?? 'GET'),
                    escapeshellarg($request['uri'] ?? '/'),
                    escapeshellarg($out),
                );
                exec('cd ' . escapeshellarg($dir) . ' && ' . $env . ' timeout 60 ' . escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' > /dev/null 2>&1');
                $sites = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
                foreach (is_array($sites) ? $sites : [] as $id => $seen) {
                    foreach (is_array($seen) ? array_keys($seen) : [] as $class) {
                        $merged[(int) $id][(string) $class] = true;
                    }
                }
            }
        } finally {
            @unlink($script);
            @unlink($out);
        }

        @mkdir($this->cacheDir, 0777, true);
        file_put_contents($file, json_encode($merged));

        return $merged;
    }

    /**
     * @param  array<mixed> $data
     * @return array<int, array<string, true>>
     */
    private function normalized(array $data): array
    {
        $result = [];
        foreach ($data as $id => $seen) {
            foreach (is_array($seen) ? array_keys($seen) : [] as $class) {
                $result[(int) $id][(string) $class] = true;
            }
        }

        return $result;
    }
}
