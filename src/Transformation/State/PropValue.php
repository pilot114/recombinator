<?php

declare(strict_types=1);

namespace Recombinator\Transformation\State;

use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Известное значение свойства. Массив с литеральными ключами хранится поэлементно:
 * после ухода объекта в неизвестный код часть ключей может оставаться известной
 * (complete = false).
 *
 * @internal
 */
final readonly class PropValue
{
    /**
     * @param array<int|string, Node\Expr>|null $items
     */
    private function __construct(
        private ?Node\Expr $value,
        private ?array $items,
        private bool $complete,
    ) {
    }

    public static function of(Node\Expr $value): self
    {
        if ($value instanceof Node\Expr\Array_) {
            $items = [];
            $next  = 0;
            foreach ($value->items as $item) {
                if (!$item instanceof Node\ArrayItem || $item->unpack || $item->byRef) {
                    return new self($value, null, false);
                }

                if (!$item->key instanceof \PhpParser\Node\Expr) {
                    $key = $next;
                } elseif ($item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_) {
                    $key = $item->key->value;
                } else {
                    return new self($value, null, false);
                }

                // PHP приводит числовые строки к int
                $key         = is_string($key) && (string) (int) $key === $key ? (int) $key : $key;
                $items[$key] = $item->value;
                if (is_int($key) && $key >= $next) {
                    $next = $key + 1;
                }
            }

            return new self(null, $items, true);
        }

        return new self($value, null, true);
    }

    public function isArray(): bool
    {
        return $this->items !== null;
    }

    public function toExpr(): ?Node\Expr
    {
        if ($this->items === null) {
            return $this->value instanceof \PhpParser\Node\Expr ? BodyInliner::clone($this->value) : null;
        }

        if (!$this->complete) {
            return null;
        }

        $items = [];
        foreach ($this->items as $key => $value) {
            $items[] = new Node\ArrayItem(
                BodyInliner::clone($value),
                is_int($key) ? new Node\Scalar\Int_($key) : new Node\Scalar\String_($key)
            );
        }

        return new Node\Expr\Array_($items, ['kind' => Node\Expr\Array_::KIND_SHORT]);
    }

    public function item(Node\Expr $key): ?Node\Expr
    {
        $k = $this->key($key);
        if ($k === null || $this->items === null || !array_key_exists($k, $this->items)) {
            return null;
        }

        return BodyInliner::clone($this->items[$k]);
    }

    public function hasKey(Node\Expr $key): ?bool
    {
        $k = $this->key($key);
        if ($k === null || $this->items === null) {
            return null;
        }

        if (array_key_exists($k, $this->items)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    public function withItem(Node\Expr $key, Node\Expr $value): self
    {
        $k = $this->key($key);
        if ($k === null || $this->items === null) {
            return new self(null, [], false);
        }

        $items     = $this->items;
        $items[$k] = $value;

        return new self(null, $items, $this->complete);
    }

    /** Массив с неизвестным содержимым. */
    public static function unknownArray(): self
    {
        return new self(null, [], false);
    }

    /** Ключ записан неизвестным значением: этот ключ и полнота массива больше не известны. */
    public function withUnknownItem(Node\Expr $key): self
    {
        $k = $this->key($key);
        if ($k === null || $this->items === null) {
            return self::unknownArray();
        }

        $items = $this->items;
        unset($items[$k]);

        return new self(null, $items, false);
    }

    private function key(Node\Expr $key): int|string|null
    {
        if ($key instanceof Node\Scalar\Int_) {
            return $key->value;
        }

        if ($key instanceof Node\Scalar\String_) {
            return (string) (int) $key->value === $key->value ? (int) $key->value : $key->value;
        }

        return null;
    }
}
