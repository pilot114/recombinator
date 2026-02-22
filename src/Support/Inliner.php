<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir as MagicDir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Inlines all include/require/include_once/require_once statements
 * by replacing them with the actual code from included files.
 *
 * Each included file's user-defined functions, classes, interfaces, traits,
 * and constants receive a unique per-file prefix (f1_, f2_, …) to avoid
 * naming conflicts. After all inlining is done, a global renaming pass
 * updates every call-site in the assembled result — including those
 * that originally lived in the entry point.
 *
 * Supported path expressions in include/require:
 *   - string literals:            'lib.php'
 *   - __DIR__ magic constant:     __DIR__
 *   - concatenations of the above: __DIR__ . '/lib.php'
 *
 * Unsupported (left as-is):
 *   - variable paths:             include $path
 *   - paths to non-existent files
 */
class Inliner
{
    private readonly Parser $parser;

    private readonly Standard $printer;

    /** @var array<string, true> Tracks real paths already processed by include_once/require_once */
    private array $includedOnce = [];

    private int $fileIndex = 0;

    /** @var array<string, string> old_name → new_name for every renamed function */
    private array $functionRenames = [];

    /** @var array<string, string> old_name → new_name for every renamed class/interface/trait */
    private array $classRenames = [];

    /** @var array<string, string> old_name → new_name for every renamed constant */
    private array $constantRenames = [];

    public function __construct(private readonly string $entryPoint)
    {
        $this->parser  = new ParserFactory()->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * Returns the fully inlined PHP code as a string.
     */
    public function inline(): string
    {
        $stmts = $this->parseFile($this->entryPoint);
        $stmts = $this->processStmts($stmts, dirname($this->entryPoint));

        // Final pass: rename all external call-sites that still use old names
        // (e.g. entry-point code that calls functions defined in included files).
        $stmts = $this->applyGlobalRenames($stmts);

        return $this->printer->prettyPrintFile($stmts);
    }

    /**
     * @return list<Node\Stmt>
     */
    private function parseFile(string $path): array
    {
        $code = file_get_contents($path);

        return $this->parser->parse($code) ?? [];
    }

    /**
     * Walks a statement list and replaces each top-level include/require node
     * with the inlined content of the referenced file.
     *
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function processStmts(array $stmts, string $baseDir): array
    {
        $result = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof Include_) {
                $inlined = $this->inlineInclude($stmt->expr, $baseDir);

                if ($inlined !== null) {
                    array_push($result, ...$inlined);
                    continue;
                }
            }

            $result[] = $stmt;
        }

        return $result;
    }

    /**
     * @return list<Node\Stmt>|null  Returns null when the path cannot be resolved statically.
     */
    private function inlineInclude(Include_ $node, string $baseDir): ?array
    {
        $resolvedPath = $this->resolveIncludeExpr($node->expr, $baseDir);

        if ($resolvedPath === null || !file_exists($resolvedPath)) {
            return null;
        }

        $isOnce = in_array(
            $node->type,
            [Include_::TYPE_INCLUDE_ONCE, Include_::TYPE_REQUIRE_ONCE],
            true,
        );

        if ($isOnce) {
            $realPath = realpath($resolvedPath);

            if ($realPath !== false && isset($this->includedOnce[$realPath])) {
                // Already included – emit nothing
                return [];
            }

            if ($realPath !== false) {
                $this->includedOnce[$realPath] = true;
            }
        }

        $prefix = 'f' . (++$this->fileIndex) . '_';
        $stmts  = $this->parseFile($resolvedPath);
        $stmts  = $this->applyPrefix($stmts, $prefix);

        // Recursively process nested includes inside the inlined file
        return $this->processStmts($stmts, dirname($resolvedPath));
    }

    /**
     * Statically evaluates an include/require path expression.
     *
     * Handles:
     *   - string literals:              'path/to/file.php'
     *   - __DIR__ magic constant:       __DIR__
     *   - concatenation of the above:   __DIR__ . '/file.php'
     *
     * Returns null for anything that cannot be resolved at parse time.
     */
    private function resolveIncludeExpr(Node\Expr $expr, string $baseDir): ?string
    {
        if ($expr instanceof String_) {
            return $this->resolvePath($expr->value, $baseDir);
        }

        if ($expr instanceof MagicDir) {
            return $baseDir;
        }

        if ($expr instanceof Concat) {
            $left  = $this->resolveIncludeExpr($expr->left, $baseDir);
            $right = $this->resolveIncludeExpr($expr->right, $baseDir);

            if ($left !== null && $right !== null) {
                return $left . $right;
            }
        }

        return null;
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }

    /**
     * Applies a unique prefix to all user-defined symbols inside $stmts
     * and records every rename in the global rename maps so that
     * external call-sites can be updated in the final pass.
     *
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function applyPrefix(array $stmts, string $prefix): array
    {
        // First pass: collect all names defined at any depth in this file
        $collector  = new InlinerNameCollector();
        $traverser1 = new NodeTraverser();
        $traverser1->addVisitor($collector);
        $traverser1->traverse($stmts);

        $names = $collector->getDefinedNames();

        // Record renames in the global maps for the final pass
        foreach ($names['functions'] as $name) {
            $this->functionRenames[$name] = $prefix . $name;
        }

        foreach ($names['classes'] as $name) {
            $this->classRenames[$name] = $prefix . $name;
        }

        foreach ($names['constants'] as $name) {
            $this->constantRenames[$name] = $prefix . $name;
        }

        // Second pass: rename definitions and internal references
        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new InlinerPrefixApplier($prefix, $names));

        return $traverser2->traverse($stmts);
    }

    /**
     * Applies the global rename maps to all statements.
     * This updates call-sites in the entry point (or any file that referenced
     * symbols now living under a new prefixed name).
     *
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function applyGlobalRenames(array $stmts): array
    {
        if ($this->functionRenames === [] && $this->classRenames === [] && $this->constantRenames === []) {
            return $stmts;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new InlinerGlobalRenamer(
            $this->functionRenames,
            $this->classRenames,
            $this->constantRenames,
        ));

        return $traverser->traverse($stmts);
    }
}

/**
 * Collects all user-defined function, class (incl. interface/trait), and
 * constant names from a parsed file's AST.
 *
 * @internal
 */
class InlinerNameCollector extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $functions = [];

    /** @var list<string> */
    private array $classes = [];

    /** @var list<string> */
    private array $constants = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Function_) {
            $this->functions[] = (string) $node->name;
        } elseif ($node instanceof Class_ && $node->name instanceof \PhpParser\Node\Identifier) {
            $this->classes[] = (string) $node->name;
        } elseif ($node instanceof Interface_) {
            $this->classes[] = (string) $node->name;
        } elseif ($node instanceof Trait_) {
            $this->classes[] = (string) $node->name;
        } elseif ($node instanceof Const_) {
            foreach ($node->consts as $const) {
                $this->constants[] = (string) $const->name;
            }
        } elseif (
            $node instanceof FuncCall
            && $node->name instanceof Name
            && strtolower($node->name->toString()) === 'define'
        ) {
            $args = $node->getArgs();

            if (isset($args[0]) && $args[0]->value instanceof String_) {
                $this->constants[] = $args[0]->value->value;
            }
        }

        return null;
    }

    /**
     * @return array{functions: list<string>, classes: list<string>, constants: list<string>}
     */
    public function getDefinedNames(): array
    {
        return [
            'functions' => $this->functions,
            'classes'   => $this->classes,
            'constants' => $this->constants,
        ];
    }
}

/**
 * Renames all user-defined symbols that appear in the collected name lists
 * by prepending a unique prefix. Only definitions and their direct references
 * inside the same file are renamed; external / built-in symbols are untouched.
 *
 * @internal
 */
class InlinerPrefixApplier extends NodeVisitorAbstract
{
    /**
     * @param array{functions: list<string>, classes: list<string>, constants: list<string>} $definedNames
     */
    public function __construct(
        private readonly string $prefix,
        private readonly array $definedNames,
    ) {
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Function_) {
            $this->renameFunction($node);
        } elseif ($node instanceof FuncCall) {
            $this->renameFuncCall($node);
        } elseif ($node instanceof Class_) {
            $this->renameClass($node);
        } elseif ($node instanceof Interface_) {
            $this->renameInterface($node);
        } elseif ($node instanceof Trait_) {
            $this->renameTrait($node);
        } elseif ($node instanceof New_) {
            $this->renameNew($node);
        } elseif ($node instanceof StaticCall) {
            $this->renameStaticCall($node);
        } elseif ($node instanceof StaticPropertyFetch) {
            $this->renameStaticPropertyFetch($node);
        } elseif ($node instanceof ClassConstFetch) {
            $this->renameClassConstFetch($node);
        } elseif ($node instanceof Instanceof_) {
            $this->renameInstanceof($node);
        } elseif ($node instanceof Const_) {
            $this->renameConst($node);
        } elseif ($node instanceof ConstFetch) {
            $this->renameConstFetch($node);
        }

        return null;
    }

    private function renameFunction(Function_ $node): void
    {
        if (in_array((string) $node->name, $this->definedNames['functions'], true)) {
            $node->name = new Identifier($this->prefix . $node->name);
        }
    }

    private function renameFuncCall(FuncCall $node): void
    {
        if (!($node->name instanceof Name)) {
            return;
        }

        $name = $node->name->toString();

        // Handle define('CONST_NAME', value) — rename the constant's string key
        if (strtolower($name) === 'define') {
            $args = $node->getArgs();

            if (isset($args[0]) && $args[0]->value instanceof String_) {
                $constName = $args[0]->value->value;

                if (in_array($constName, $this->definedNames['constants'], true)) {
                    $args[0]->value = new String_($this->prefix . $constName);
                }
            }

            return;
        }

        if (in_array($name, $this->definedNames['functions'], true)) {
            $node->name = new Name($this->prefix . $name);
        }
    }

    private function renameClass(Class_ $node): void
    {
        if ($node->name instanceof \PhpParser\Node\Identifier && in_array((string) $node->name, $this->definedNames['classes'], true)) {
            $node->name = new Identifier($this->prefix . $node->name);
        }
    }

    private function renameInterface(Interface_ $node): void
    {
        if (in_array((string) $node->name, $this->definedNames['classes'], true)) {
            $node->name = new Identifier($this->prefix . $node->name);
        }
    }

    private function renameTrait(Trait_ $node): void
    {
        if (in_array((string) $node->name, $this->definedNames['classes'], true)) {
            $node->name = new Identifier($this->prefix . $node->name);
        }
    }

    private function renameNew(New_ $node): void
    {
        if ($node->class instanceof Name) {
            $className = $node->class->toString();

            if (in_array($className, $this->definedNames['classes'], true)) {
                $node->class = new Name($this->prefix . $className);
            }
        }
    }

    private function renameStaticCall(StaticCall $node): void
    {
        if ($node->class instanceof Name) {
            $className = $node->class->toString();

            if (in_array($className, $this->definedNames['classes'], true)) {
                $node->class = new Name($this->prefix . $className);
            }
        }
    }

    private function renameStaticPropertyFetch(StaticPropertyFetch $node): void
    {
        if ($node->class instanceof Name) {
            $className = $node->class->toString();

            if (in_array($className, $this->definedNames['classes'], true)) {
                $node->class = new Name($this->prefix . $className);
            }
        }
    }

    private function renameClassConstFetch(ClassConstFetch $node): void
    {
        if ($node->class instanceof Name) {
            $className = $node->class->toString();

            if (in_array($className, $this->definedNames['classes'], true)) {
                $node->class = new Name($this->prefix . $className);
            }
        }
    }

    private function renameInstanceof(Instanceof_ $node): void
    {
        if ($node->class instanceof Name) {
            $className = $node->class->toString();

            if (in_array($className, $this->definedNames['classes'], true)) {
                $node->class = new Name($this->prefix . $className);
            }
        }
    }

    private function renameConst(Const_ $node): void
    {
        foreach ($node->consts as $const) {
            if (in_array((string) $const->name, $this->definedNames['constants'], true)) {
                $const->name = new Identifier($this->prefix . $const->name);
            }
        }
    }

    private function renameConstFetch(ConstFetch $node): void
    {
        $name = $node->name->toString();

        // Never rename PHP built-in keywords masquerading as constants
        if (in_array(strtolower($name), ['true', 'false', 'null'], true)) {
            return;
        }

        if (in_array($name, $this->definedNames['constants'], true)) {
            $node->name = new Name($this->prefix . $name);
        }
    }
}

/**
 * Applies the global rename maps (accumulated across all included files)
 * to every node in the assembled AST.
 *
 * This fixes references in the entry point (and in any file that calls
 * symbols originally defined in another included file).
 *
 * @internal
 */
class InlinerGlobalRenamer extends NodeVisitorAbstract
{
    /**
     * @param array<string, string> $functionRenames
     * @param array<string, string> $classRenames
     * @param array<string, string> $constantRenames
     */
    public function __construct(
        private readonly array $functionRenames,
        private readonly array $classRenames,
        private readonly array $constantRenames,
    ) {
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof FuncCall) {
            $this->renameFuncCall($node);
        } elseif ($node instanceof New_) {
            $this->renameClassRef($node->class);
        } elseif ($node instanceof StaticCall) {
            $this->renameClassRef($node->class);
        } elseif ($node instanceof StaticPropertyFetch) {
            $this->renameClassRef($node->class);
        } elseif ($node instanceof ClassConstFetch) {
            $this->renameClassRef($node->class);
        } elseif ($node instanceof Instanceof_) {
            $this->renameClassRef($node->class);
        } elseif ($node instanceof ConstFetch) {
            $this->renameConstFetch($node);
        }

        return null;
    }

    private function renameFuncCall(FuncCall $node): void
    {
        if (!($node->name instanceof Name)) {
            return;
        }

        $name = $node->name->toString();

        // define() itself should not be renamed; its argument was already handled
        if (strtolower($name) === 'define') {
            return;
        }

        if (isset($this->functionRenames[$name])) {
            $node->name = new Name($this->functionRenames[$name]);
        }
    }

    private function renameClassRef(mixed &$classNode): void
    {
        if (!($classNode instanceof Name)) {
            return;
        }

        $name = $classNode->toString();

        if (isset($this->classRenames[$name])) {
            $classNode = new Name($this->classRenames[$name]);
        }
    }

    private function renameConstFetch(ConstFetch $node): void
    {
        $name = $node->name->toString();

        if (in_array(strtolower($name), ['true', 'false', 'null'], true)) {
            return;
        }

        if (isset($this->constantRenames[$name])) {
            $node->name = new Name($this->constantRenames[$name]);
        }
    }
}
