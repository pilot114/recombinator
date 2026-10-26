<?php

declare(strict_types=1);

namespace Recombinator\Transformation\State;

use PhpParser\Node;

/**
 * Состояние отслеживаемого объекта: класс и известные значения свойств.
 *
 * @internal
 */
final readonly class ObjectState
{
    /**
     * @param array<string, true>            $stable  свойства, не меняющиеся после конструктора
     * @param array<string, PropValue|null>  $props   null — значение неизвестно
     */
    public function __construct(
        public Node\Stmt\Class_ $class,
        private array $stable,
        public array $props = [],
    ) {
    }

    public function with(string $prop, ?PropValue $value): self
    {
        $props        = $this->props;
        $props[$prop] = $value;

        return new self($this->class, $this->stable, $props);
    }

    /** Объект ушёл в неизвестный код: известны только стабильные свойства. */
    public function escaped(): self
    {
        $props = [];
        foreach ($this->props as $name => $value) {
            $props[$name] = isset($this->stable[$name]) ? $value : null;
        }

        return new self($this->class, $this->stable, $props);
    }
}
