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
    public $scopeName;
    public $diff;
    protected $ast;

    public function beforeTraverse(array $nodes)
    {
        $this->ast = $nodes;
//        echo sprintf("*** %s start ***\n", (new \ReflectionClass(static::class))->getShortName());
    }

    public function afterTraverse(array $nodes)
    {
//        echo sprintf("*** %s end ***\n", (new \ReflectionClass(static::class))->getShortName());
    }

    public function leaveNode(Node $node)
    {
        if ($node->getAttribute('remove')) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node->getAttribute('replace')) {
            return $node->getAttribute('replace');
        }
    }

    /**
     * напечатать AST и и соответствующий ему код
     */
    protected function debug(Node $stmt, $onlyClass = false)
    {
        // удаленный узел - у него нет позиции
        if ($stmt->getStartLine() < 0) {
            echo sprintf("%s\n", get_class($stmt));
        } else {
            echo sprintf(
                "%s %s:%s-%s\n", get_class($stmt),
                $stmt->getStartLine(), $stmt->getStartFilePos(), $stmt->getEndFilePos()
            );
        }
        if ($onlyClass) return;
        echo (new PrettyDumper())->dump($stmt) . "\n";
        echo (new StandardPrinter)->prettyPrint([$stmt]) . "\n";
    }

    /**
     * Создание уникального идентификатора на основе сигнатур узла
     */
    protected function buildUidByNode(Node $stmt)
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
     */
    protected function findNode($className, $node = null)
    {
        $nodeFinder = new NodeFinder();
        return $nodeFinder->findInstanceOf($node ?? $this->ast, $className);
    }

    /**
     * Выводит список нод с их позициями (строка:смещение в файле)
     */
    protected function printNodesPos($className)
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
                str_repeat(' ', ($maxLenToken+3) - $node->getAttribute('lenToken')),
                $node->getAttribute('startLine'),
                $node->getAttribute('startFilePos'),
                $node->getAttribute('endFilePos')
            );
        }
        echo "\n";
    }

    // расставляет arg_index/arg_default в однострочной функции
    protected function markupFunctionBody(Node $node)
    {
        $return = $node->stmts[0];
        $params = [];
        foreach ($node->params as $i => $param) {
            $params[$param->var->name] = [
                'index' => $i,
                'default' => $param->default->value ?? null,
            ];
        }
        $vars = $this->findNode(Node\Expr\Variable::class, $return->expr);
        foreach ($vars as $var) {
            if (isset($params[$var->name])) {
                $var->setAttribute('arg_index', $params[$var->name]['index']);
                $var->setAttribute('arg_default', $params[$var->name]['default']);
            }
        }
        return $node;
    }
}