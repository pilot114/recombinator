<?php

declare(strict_types=1);

namespace Recombinator\Domain;

/**
 * Граница между разными типами эффектов
 */
class EffectBoundary
{
    public function __construct(
        public readonly SideEffectType $fromEffect,
        public readonly SideEffectType $toEffect,
        public readonly int $position,
        public readonly int $prevPosition,
    ) {}

    /**
     * Возвращает расстояние между границами
     */
    public function getDistance(): int
    {
        return $this->position - $this->prevPosition;
    }

    /**
     * Проверяет, является ли переход от чистого к нечистому
     */
    public function isPureToImpure(): bool
    {
        return $this->fromEffect->isPure() && !$this->toEffect->isPure();
    }

    /**
     * Проверяет, является ли переход от нечистого к чистому
     */
    public function isImpureToPure(): bool
    {
        return !$this->fromEffect->isPure() && $this->toEffect->isPure();
    }
}
