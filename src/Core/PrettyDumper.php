<?php

declare(strict_types=1);

namespace Recombinator\Core;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;

/**
 * Цветной вывод AST дерева
 *
 * Расширяет стандартный NodeDumper из PhpParser, добавляя цветное форматирование
 * для улучшения читаемости при отладке и анализе AST. Использует ANSI escape коды
 * для подсветки различных элементов дерева (типы узлов, свойства, значения).
 */
class PrettyDumper
{
    protected bool $dumpPositions = false;

    /**
     * @param Node|array<int, Node> $node
     */
    public function dump(mixed $node, ?string $code = null): string
    {
        return $this->dumpRecursive($node);
    }

    /**
     * @param Node|array<int, Node>|Comment|mixed $node
     */
    protected function dumpRecursive(mixed $node, bool $isChild = false): string
    {
        if ($node instanceof Node) {
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
                        $r .= $this->dumpFlags((int) $value);
                    } elseif ('type' === $key && $node instanceof Include_) {
                        $r .= $this->dumpIncludeType((int) $value);
                    } elseif (
                        'type' === $key
                        && ($node instanceof Use_ || $node instanceof UseUse || $node instanceof GroupUse)
                    ) {
                        $r .= $this->dumpUseType((int) $value);
                    } else {
                        $r .= $value;
                    }
                } else {
                    $r .= str_replace("\n", "\n    ", $this->dumpRecursive($value, true));
                }
            }
        } elseif (is_array($node)) {
            $r = '';

            foreach ($node as $value) {
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

    protected function dumpPosition(Node $node): ?string
    {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();
        if ($startLine === -1 || $endLine === -1) {
            return null;
        }

        return sprintf('[%d - %d]', $startLine, $endLine);
    }

    protected function dumpFlags(int $flags): string
    {
        $strs = [];
        if (($flags & Node\Stmt\Class_::MODIFIER_PUBLIC) !== 0) {
            $strs[] = 'PUBLIC';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_PROTECTED) !== 0) {
            $strs[] = 'PROTECTED';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_PRIVATE) !== 0) {
            $strs[] = 'PRIVATE';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_ABSTRACT) !== 0) {
            $strs[] = 'ABSTRACT';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_STATIC) !== 0) {
            $strs[] = 'STATIC';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_FINAL) !== 0) {
            $strs[] = 'FINAL';
        }

        if (($flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0) {
            $strs[] = 'READONLY';
        }

        if ($strs !== []) {
            return implode(' | ', $strs) . ' (' . $flags . ')';
        }

        return (string) $flags;
    }

    protected function dumpIncludeType(int $type): string
    {
        return match ($type) {
            Include_::TYPE_INCLUDE => 'TYPE_INCLUDE',
            Include_::TYPE_INCLUDE_ONCE => 'TYPE_INCLUDE_ONCE',
            Include_::TYPE_REQUIRE => 'TYPE_REQUIRE',
            Include_::TYPE_REQUIRE_ONCE => 'TYPE_REQUIRE_ONCE',
            default => (string) $type,
        };
    }

    protected function dumpUseType(int $type): string
    {
        return match ($type) {
            Use_::TYPE_UNKNOWN => 'TYPE_UNKNOWN',
            Use_::TYPE_NORMAL => 'TYPE_NORMAL',
            Use_::TYPE_FUNCTION => 'TYPE_FUNCTION',
            Use_::TYPE_CONSTANT => 'TYPE_CONSTANT',
            default => (string) $type,
        };
    }

    protected function red(string $text): string
    {
        return "\e[31m" . $text . "\033[0m";
    }

    protected function yellow(string $text): string
    {
        return "\e[33m" . $text . "\033[0m";
    }

    protected function blue(string $text): string
    {
        return "\e[36m" . $text . "\033[0m";
    }

    protected function green(string $text): string
    {
        return "\e[32m" . $text . "\033[0m";
    }
}
