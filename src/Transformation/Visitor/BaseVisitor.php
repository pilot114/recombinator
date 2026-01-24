<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Core\PrettyDumper;

class BaseVisitor extends NodeVisitorAbstract
{
    /**
     * @var mixed 
     */
    public $scopeName;

    /**
     * @var mixed 
     */

    public $diff;

    /**
     * @var mixed 
     */

    protected $ast;

    public function beforeTraverse(array $nodes): ?array
    {
        $this->ast = $nodes;
        //        echo sprintf("*** %s start ***\n", (new \ReflectionClass(static::class))->getShortName());
        return null;
    }

    public function afterTraverse(array $nodes): ?array
    {
        //        echo sprintf("*** %s end ***\n", (new \ReflectionClass(static::class))->getShortName());
        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node->getAttribute('remove')) {
            return NodeTraverser::REMOVE_NODE;
        }

        $replace = $node->getAttribute('replace');
        if ($replace !== null) {
            return $replace;
        }

        return null;
    }

    /**
     * напечатать AST и и соответствующий ему код
     */
    protected function debug(Node $stmt, bool $onlyClass = false): void
    {
        // удаленный узел - у него нет позиции
        if ($stmt->getStartLine() < 0) {
            echo sprintf("%s\n", $stmt::class);
        } else {
            echo sprintf(
                "%s %s:%s-%s\n", $stmt::class,
                $stmt->getStartLine(), $stmt->getStartFilePos(), $stmt->getEndFilePos()
            );
        }

        if ($onlyClass) {
            return;
        }

        echo new PrettyDumper()->dump($stmt) . "\n";
        echo (new StandardPrinter)->prettyPrint([$stmt]) . "\n";
    }

    /**
     * Создание уникального идентификатора на основе сигнатур узла
     */
    protected function buildUidByNode(Node $stmt): int
    {
        return crc32($stmt->getStartFilePos() . $stmt->getEndFilePos());
    }

    /**
     * упрощенный способ найти узлы определенного типа.
     * найденные узлы не содержат ссылок на родителей!
     *
     * Пример:
     * $assigns = $p->findNode(Assign::class);
     * $vars = array_map(function($x) { return $x->var->name; }, $assigns);
     *
     * @param class-string<Node> $className
     * @param Node|array<Node>|null $node
     * @return array<Node>
     */
    protected function findNode(string $className, $node = null): array
    {
        $nodeFinder = new NodeFinder();
        $searchIn = $node ?? $this->ast;
        if (!is_array($searchIn) && !($searchIn instanceof Node)) {
            return [];
        }
        return $nodeFinder->findInstanceOf($searchIn, $className);
    }

    /**
     * Выводит список нод с их позициями (строка:смещение в файле)
     */
    protected function printNodesPos(string $className): void
    {
        $nodes = $this->findNode($className);

        $maxLenToken = 0;
        foreach ($nodes as $node) {
            $lenToken = strlen((new StandardPrinter)->prettyPrint([$node]));
            if ($maxLenToken < $lenToken) {
                $maxLenToken = $lenToken;
            }

            $node->setAttribute('lenToken', $lenToken);
        }

        echo $className . " count: " . count($nodes) . "\n";
        foreach ($nodes as $node) {
            echo sprintf(
                "%s%s%s:%s-%s\n",
                (new StandardPrinter)->prettyPrint([$node]),
                str_repeat(' ', ($maxLenToken+3) - (int) ($node->getAttribute('lenToken') ?? 0)),
                (string) ($node->getAttribute('startLine') ?? ''),
                (string) ($node->getAttribute('startFilePos') ?? ''),
                (string) ($node->getAttribute('endFilePos') ?? '')
            );
        }

        echo "\n";
    }

    // расставляет arg_index/arg_default в однострочной функции
    protected function markupFunctionBody(Node $node): Node
    {
        if (!property_exists($node, 'stmts') || !is_array($node->stmts) || !isset($node->stmts[0])) {
            return $node;
        }

        if (!property_exists($node, 'params') || !is_array($node->params)) {
            return $node;
        }

        $return = $node->stmts[0];
        $params = [];
        foreach ($node->params as $i => $param) {
            if (!($param instanceof Node\Param)) {
                continue;
            }
            $var = $param->var;
            if (!($var instanceof Node\Expr\Variable) || !is_string($var->name)) {
                continue;
            }
            $params[$var->name] = [
                'index' => $i,
                'default' => ($param->default instanceof Node\Scalar ? $param->default->value : null) ?? null,
            ];
        }

        if (!property_exists($return, 'expr')) {
            return $node;
        }

        $vars = $this->findNode(Node\Expr\Variable::class, $return->expr);
        foreach ($vars as $var) {
            if ($var instanceof Node\Expr\Variable && is_string($var->name) && isset($params[$var->name])) {
                $var->setAttribute('arg_index', $params[$var->name]['index']);
                $var->setAttribute('arg_default', $params[$var->name]['default']);
            }
        }

        return $node;
    }
}
