<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Core\PrettyDumper;

class BaseVisitor extends NodeVisitorAbstract
{
    public ?string $scopeName = null;

    public ?string $diff = null;

    /** @var array<Node> */
    protected array $ast = [];

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
        if ($replace instanceof Node) {
            return $replace;
        }

        if (is_array($replace)) {
            /**
 * @var array<Node> $replace
*/
            return $replace;
        }

        if (is_int($replace)) {
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
                "%s %s:%s-%s\n",
                $stmt::class,
                $stmt->getStartLine(),
                $stmt->getStartFilePos(),
                $stmt->getEndFilePos()
            );
        }

        if ($onlyClass) {
            return;
        }

        echo new PrettyDumper()->dump($stmt) . "\n";
        echo new StandardPrinter()->prettyPrint([$stmt]) . "\n";
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
     * @template T of Node
     * @param    class-string<T>       $className
     * @param    Node|array<Node>|null $node
     * @return   array<T>
     */
    protected function findNode(string $className, Node|array|null $node = null): array
    {
        $nodeFinder = new NodeFinder();
        $searchIn = $node ?? $this->ast;

        /**
 * @var array<T>
*/
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
            $lenToken = strlen(new StandardPrinter()->prettyPrint([$node]));
            if ($maxLenToken < $lenToken) {
                $maxLenToken = $lenToken;
            }

            $node->setAttribute('lenToken', $lenToken);
        }

        echo $className . " count: " . count($nodes) . "\n";
        foreach ($nodes as $node) {
            $lenToken = $node->getAttribute('lenToken');
            $startLine = $node->getAttribute('startLine');
            $startFilePos = $node->getAttribute('startFilePos');
            $endFilePos = $node->getAttribute('endFilePos');
            echo sprintf(
                "%s%s%s:%s-%s\n",
                new StandardPrinter()->prettyPrint([$node]),
                str_repeat(' ', ($maxLenToken + 3) - (is_int($lenToken) ? $lenToken : 0)),
                is_scalar($startLine) ? (string) $startLine : '',
                is_scalar($startFilePos) ? (string) $startFilePos : '',
                is_scalar($endFilePos) ? (string) $endFilePos : ''
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
            if (!($var instanceof Node\Expr\Variable)) {
                continue;
            }

            if (!is_string($var->name)) {
                continue;
            }

            $default = null;
            if (
                $param->default instanceof Node\Scalar\LNumber
                || $param->default instanceof Node\Scalar\DNumber
                || $param->default instanceof Node\Scalar\String_
            ) {
                $default = $param->default->value;
            }

            $params[$var->name] = [
                'index' => $i,
                'default' => $default,
            ];
        }

        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof \PhpParser\Node\Expr) {
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
