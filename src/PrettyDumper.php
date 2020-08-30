<?php

namespace Recombinator;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeDumper;

class PrettyDumper extends NodeDumper
{
    protected $dumpPositions;

    public function dump($node, string $code = null) : string {
        return $this->dumpRecursive($node);
    }

    protected function dumpRecursive($node, $isChild = false) {
        if ($node instanceof Node) {
            // TODO: можно выводить кастомные аттрибуты
//            $attrs = $node->getAttributes();
//            unset($attrs['next'], $attrs['previous'], $attrs['parent']);
//            var_dump($attrs);

            $r = $node->getType();
            $r = $this->yellow($r);

            // если выводим позицию
            if ($this->dumpPositions && null !== $p = $this->dumpPosition($node)) {
                $r .= $p;
            }

            // содержимое ноды - параметры
            foreach ($node->getSubNodeNames() as $key) {
                $r .= "\n    "
                    . $this->green($key)
                    . ': ';
                $value = $node->$key;
                if (null === $value) {
                    $r .= 'null';
                } elseif (false === $value) {
                    $r .= 'false';
                } elseif (true === $value) {
                    $r .= 'true';
                } elseif (is_scalar($value)) {
                    if ('flags' === $key || 'newModifier' === $key) {
                        $r .= $this->dumpFlags($value);
                    } elseif ('type' === $key && $node instanceof Include_) {
                        $r .= $this->dumpIncludeType($value);
                    } elseif ('type' === $key
                        && ($node instanceof Use_ || $node instanceof UseUse || $node instanceof GroupUse)) {
                        $r .= $this->dumpUseType($value);
                    } else {
                        $r .= $value;
                    }
                } else {
                    $r .= str_replace("\n", "\n    ", $this->dumpRecursive($value, true));
                }
            }

        } elseif (is_array($node)) {
            $r = '';

            foreach ($node as $key => $value) {
                $r .= "\n";

                if (null === $value) {
                    $r .= 'null';
                } elseif (false === $value) {
                    $r .= 'false';
                } elseif (true === $value) {
                    $r .= 'true';
                } elseif (is_scalar($value)) {
                    $r .= '    ' . $value;
                } else {
                    if ($isChild) {
                        $r .= '    ';
                    }
                    $r .= $this->dumpRecursive($value, true);
                }
            }
        } elseif ($node instanceof Comment) {
            return $node->getReformattedText();
        } else {
            throw new \InvalidArgumentException('Can only dump nodes and arrays.');
        }

        return $r;
    }

    protected function red($text) {
        return "\e[31m".$text."\033[0m";
    }
    protected function yellow($text) {
        return "\e[33m".$text."\033[0m";
    }
    protected function blue($text) {
        return "\e[36m".$text."\033[0m";
    }
    protected function green($text) {
        return "\e[32m".$text."\033[0m";
    }
}
